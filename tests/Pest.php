<?php

declare(strict_types=1);

use Illuminate\Testing\PendingCommand;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Milzer\HttpLogger\Core\HttpLogger;
use Milzer\HttpLogger\Core\LoggingOptions;
use Milzer\HttpLogger\Tests\Support\ArrayLogger;
use Milzer\HttpLogger\Tests\Support\TemporaryDirectory;
use Milzer\HttpLogger\Tests\Support\TestConnector;
use Orchestra\Testbench\TestCase;
use Pest\TestSuite;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

require_once __DIR__.'/Support/Requests.php';

pest()
    ->beforeEach(function (): void {
        MockClient::destroyGlobal();
        HttpLogger::resolveTraceIdUsing(null);
        HttpLogger::setDefault(new HttpLogger(ArrayLogger::fresh(), new LoggingOptions(throwOnError: true)));
    })
    ->afterEach(function (): void {
        TemporaryDirectory::cleanup();
        HttpLogger::setDefault(null);
        HttpLogger::resolveTraceIdUsing(null);
    })
    ->in('Feature');

/**
 * The logger that receives every entry in the current test.
 */
function testLog(): ArrayLogger
{
    return ArrayLogger::current();
}

/**
 * Re-registers the default logger with other options, keeping the current test logger.
 */
function useOptions(LoggingOptions $options): void
{
    HttpLogger::setDefault(new HttpLogger(testLog(), $options));
}

/**
 * A logging connector that answers with the given mock responses (or one empty 200).
 */
function connector(MockResponse ...$responses): TestConnector
{
    return (new TestConnector)->withMockClient(new MockClient($responses === [] ? [MockResponse::make()] : array_values($responses)));
}

/**
 * The running Laravel (Testbench) test case, typed, for HTTP and artisan helpers.
 */
function laravel(): TestCase
{
    $test = TestSuite::getInstance()->test;

    return $test instanceof TestCase ? $test : throw new LogicException('This test does not use the Laravel test case.');
}

/**
 * A local filesystem on the current test's temporary directory (removed after the test).
 */
function temporaryFilesystem(): Filesystem
{
    return new Filesystem(new LocalFilesystemAdapter(TemporaryDirectory::current()));
}

/**
 * Runs an artisan command through the Laravel test case, typed.
 *
 * @param  array<string, mixed>  $parameters
 */
function artisan(string $command, array $parameters = []): PendingCommand
{
    $pending = laravel()->artisan($command, $parameters);

    return $pending instanceof PendingCommand ? $pending : throw new LogicException('Artisan did not return a pending command.');
}
