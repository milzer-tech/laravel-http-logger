<?php

declare(strict_types=1);

use Milzer\SaloonLogger\LoggingOptions;
use Milzer\SaloonLogger\Redaction\Redactor;

/**
 * @param  list<string>  $keys
 */
function redactor(array $keys = LoggingOptions::DEFAULT_REDACTED_KEYS): Redactor
{
    return new Redactor(LoggingOptions::DEFAULT_REDACTED_HEADERS, $keys);
}

it('matches keys regardless of case and separators', function (string $key): void {
    expect(redactor(['card_number'])->isSensitiveKey($key))->toBeTrue();
})->with(['card_number', 'cardNumber', 'CardNumber', 'card-number', 'CARD_NUMBER', 'card.number']);

it('supports wildcards', function (): void {
    $redactor = redactor(['*token*']);

    expect($redactor->isSensitiveKey('access_token'))->toBeTrue()
        ->and($redactor->isSensitiveKey('X-Refresh-Token-Id'))->toBeTrue()
        ->and($redactor->isSensitiveKey('tokenizer'))->toBeTrue()
        ->and($redactor->isSensitiveKey('holder'))->toBeFalse();
});

it('does not treat a lone wildcard as "redact everything"', function (): void {
    expect(redactor(['*'])->isSensitiveKey('anything'))->toBeFalse();
});

it('redacts nested arrays including sensitive containers', function (): void {
    expect(redactor()->array([
        'booking' => ['guest' => 'Jane', 'payment' => ['cvc' => 123]],
        'credentials' => ['password' => 'a'],
        'client_secret' => ['nested' => 'value'],
        'list' => [['token' => 'x'], ['token' => 'y']],
    ]))->toBe([
        'booking' => ['guest' => 'Jane', 'payment' => ['cvc' => '[REDACTED]']],
        'credentials' => ['password' => '[REDACTED]'],
        'client_secret' => '[REDACTED]',
        'list' => [['token' => '[REDACTED]'], ['token' => '[REDACTED]']],
    ]);
});

it('redacts headers case-insensitively', function (): void {
    expect(redactor()->headers(['authorization' => 'Bearer x', 'X-API-KEY' => 'k', 'Accept' => 'application/json']))
        ->toBe(['authorization' => '[REDACTED]', 'X-API-KEY' => '[REDACTED]', 'Accept' => 'application/json']);
});

it('redacts raw strings', function (string $input, string $expected): void {
    expect(redactor()->string($input))->toBe($expected);
})->with([
    'json pair' => ['{"user":"a","password":"p\"w"}', '{"user":"a","password":"[REDACTED]"}'],
    'json number' => ['{"cvv": 123}', '{"cvv": "[REDACTED]"}'],
    'xml element' => ['<a><Password>x</Password></a>', '<a><Password>[REDACTED]</Password></a>'],
    'xml namespaced' => ['<soap:ApiKey>k</soap:ApiKey>', '<soap:ApiKey>[REDACTED]</soap:ApiKey>'],
    'xml cdata' => ['<Secret><![CDATA[s<>]]></Secret>', '<Secret>[REDACTED]</Secret>'],
    'xml attribute' => ['<Auth user="u" token=\'t\'/>', '<Auth user="u" token=\'[REDACTED]\'/>'],
    'form' => ['a=1&password=x&b=2', 'a=1&password=%5BREDACTED%5D&b=2'],
    'untouched' => ['<Hotel>Palma</Hotel>', '<Hotel>Palma</Hotel>'],
]);
