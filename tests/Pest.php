<?php

declare(strict_types=1);

use Milzer\SaloonLogger\LoggingOptions;
use Milzer\SaloonLogger\SaloonLogger;
use Milzer\SaloonLogger\Tests\Support\ArrayLogger;
use Saloon\Http\Faking\MockClient;

require_once __DIR__.'/Support/Requests.php';

uses()
    ->beforeEach(function (): void {
        MockClient::destroyGlobal();
        $this->logger = new ArrayLogger;
        SaloonLogger::setDefault(new SaloonLogger($this->logger, new LoggingOptions(throwOnError: true)));
    })
    ->afterEach(fn () => SaloonLogger::setDefault(null))
    ->in('Feature');
