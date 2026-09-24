<?php

declare(strict_types=1);

use Milzer\HttpLogger\Core\Direction;
use Milzer\HttpLogger\Core\Serialization\BodyContext;
use Milzer\HttpLogger\Storage\FilesystemBodyStore;
use Milzer\HttpLogger\Tests\Support\TemporaryDirectory;

afterEach(function (): void {
    TemporaryDirectory::cleanup();
});

function bodyContext(string $date = '2026-09-24 15:01:16'): BodyContext
{
    return new BodyContext('21200c57ff98fa8e', Direction::Outgoing, 'response', new DateTimeImmutable($date));
}

it('stores one file per body in a folder per day', function (): void {
    $store = new FilesystemBodyStore(temporaryFilesystem(), 'gcs-logs', '/http-logs/');

    $stored = $store->store('{"a":1}', 'json', bodyContext());

    expect($stored->toArray())->toBe([
        'disk' => 'gcs-logs',
        'path' => 'http-logs/2026/09/24/150116-outgoing-21200c57ff98fa8e-response.json',
        'size' => 7,
    ])->and(temporaryFilesystem()->read($stored->path))->toBe('{"a":1}');
});

it('can store at the root of the filesystem', function (): void {
    $stored = (new FilesystemBodyStore(temporaryFilesystem(), root: ''))->store('x', 'txt', bodyContext());

    expect($stored->path)->toBe('2026/09/24/150116-outgoing-21200c57ff98fa8e-response.txt')
        ->and($stored->disk)->toBe('default');
});

it('prunes day folders older than the cutoff and removes empty parents', function (): void {
    $store = new FilesystemBodyStore(temporaryFilesystem());

    foreach (['2025-12-30', '2026-08-01', '2026-09-23', '2026-09-24'] as $day) {
        $store->store('x', 'txt', bodyContext($day.' 10:00:00'));
    }

    temporaryFilesystem()->write('http-logs/README.txt', 'not a year folder');
    temporaryFilesystem()->createDirectory('http-logs/tmp');

    $deleted = $store->prune(new DateTimeImmutable('2026-09-23'));

    expect($deleted)->toBe(2)
        ->and(temporaryFilesystem()->directoryExists('http-logs/2025'))->toBeFalse()
        ->and(temporaryFilesystem()->directoryExists('http-logs/2026/08'))->toBeFalse()
        ->and(temporaryFilesystem()->directoryExists('http-logs/2026/09/23'))->toBeTrue()
        ->and(temporaryFilesystem()->directoryExists('http-logs/2026/09/24'))->toBeTrue()
        ->and(temporaryFilesystem()->fileExists('http-logs/README.txt'))->toBeTrue()
        ->and(temporaryFilesystem()->directoryExists('http-logs/tmp'))->toBeTrue();
});
