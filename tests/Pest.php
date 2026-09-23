<?php

declare(strict_types=1);

use Milzer\SaloonLogger\LoggingOptions;
use Milzer\SaloonLogger\SaloonLogger;
use Milzer\SaloonLogger\Tests\Support\ArrayLogger;
use Milzer\SaloonLogger\Tests\Support\TestConnector;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

require_once __DIR__.'/Support/Requests.php';

pest()
    ->beforeEach(function (): void {
        MockClient::destroyGlobal();
        SaloonLogger::setDefault(new SaloonLogger(ArrayLogger::fresh(), new LoggingOptions(throwOnError: true)));
    })
    ->afterEach(function (): void {
        SaloonLogger::setDefault(null);
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
    SaloonLogger::setDefault(new SaloonLogger(testLog(), $options));
}

/**
 * A logging connector that answers with the given mock responses (or one empty 200).
 */
function connector(MockResponse ...$responses): TestConnector
{
    return (new TestConnector)->withMockClient(new MockClient($responses === [] ? [MockResponse::make()] : array_values($responses)));
}
