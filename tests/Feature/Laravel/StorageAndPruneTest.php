<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Milzer\HttpLogger\Core\HttpLogger;
use Milzer\HttpLogger\Laravel\HttpLoggerServiceProvider;
use Milzer\HttpLogger\Storage\FilesystemBodyStore;
use Milzer\HttpLogger\Tests\Support\TemporaryDirectory;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    config(['filesystems.disks.http-logs-test' => ['driver' => 'local', 'root' => TemporaryDirectory::current()]]);
    app()->register(HttpLoggerServiceProvider::class);
});

function enableStorage(): void
{
    config([
        'http-logger.storage.enabled' => true,
        'http-logger.storage.disk' => 'http-logs-test',
        'http-logger.storage.path' => 'bodies',
    ]);
}

it('has no store unless storage is enabled', function (): void {
    expect(app(HttpLogger::class)->store())->toBeNull();

    artisan('http-logger:prune')
        ->expectsOutputToContain('Body storage is disabled')
        ->assertSuccessful();
});

it('builds a filesystem store from the configured disk', function (): void {
    enableStorage();

    expect(app(HttpLogger::class)->store())->toBeInstanceOf(FilesystemBodyStore::class);
});

it('uses the default disk when none is configured', function (): void {
    config(['http-logger.storage.enabled' => true, 'http-logger.storage.disk' => null, 'http-logger.storage.path' => null]);

    expect(app(HttpLogger::class)->store())->toBeInstanceOf(FilesystemBodyStore::class);
});

it('rejects disks that are not backed by Flysystem', function (): void {
    app(FilesystemManager::class)->set('odd', Mockery::mock(Filesystem::class));
    config(['http-logger.storage.enabled' => true, 'http-logger.storage.disk' => 'odd']);

    app(HttpLogger::class);
})->throws(InvalidArgumentException::class, 'must be a Flysystem-based disk');

it('prunes day folders older than the retention period', function (): void {
    enableStorage();
    config(['http-logger.storage.retention_days' => 30]);

    $old = date('Y/m/d', strtotime('-40 days'));
    $recent = date('Y/m/d', strtotime('-2 days'));

    foreach ([$old, $recent] as $day) {
        mkdir(TemporaryDirectory::current().'/bodies/'.$day, 0777, true);
        file_put_contents(TemporaryDirectory::current().'/bodies/'.$day.'/body.json', '{}');
    }

    artisan('http-logger:prune')
        ->expectsOutputToContain('Deleted 1 day folder(s) older than 30 days.')
        ->assertSuccessful();

    expect(is_dir(TemporaryDirectory::current().'/bodies/'.$old))->toBeFalse()
        ->and(is_dir(TemporaryDirectory::current().'/bodies/'.$recent))->toBeTrue();
});

it('accepts the retention as an option', function (): void {
    enableStorage();

    artisan('http-logger:prune', ['--days' => 1])
        ->expectsOutputToContain('older than 1 days')
        ->assertSuccessful();
});

it('refuses an invalid retention', function (): void {
    enableStorage();

    artisan('http-logger:prune', ['--days' => 0])
        ->expectsOutputToContain('The retention must be at least 1 day')
        ->assertFailed();
});
