<?php

declare(strict_types=1);

use Milzer\HttpLogger\Core\LoggingOptions;
use Milzer\HttpLogger\Core\Redaction\Redactor;

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
    'xml with children' => ['<password><value>hunter2</value></password>', '<password>[REDACTED]</password>'],
    'xml deep inside other elements' => ['<A><B><C a="1"><Token>t</Token></C></B></A>', '<A><B><C a="1"><Token>[REDACTED]</Token></C></B></A>'],
    'xml self-closing is not an element pair' => ['<Hotel/><Name>x</Name>', '<Hotel/><Name>x</Name>'],
    'url credentials' => ['see https://bob:pw@host/path', 'see https://[REDACTED]@host/path'],
    'first query parameter after a url in text' => ['refused for https://host/v1/?api_key=sk_1', 'refused for https://host/v1/?api_key=%5BREDACTED%5D'],
    'first query parameter of a url at the start' => ['https://host/?token=abc&lang=de', 'https://host/?token=%5BREDACTED%5D&lang=de'],
    'free text pair' => ['invalid token=abc123 given', 'invalid token=%5BREDACTED%5D given'],
]);

it('checks every segment of flattened field names', function (string $name, bool $sensitive): void {
    expect(redactor()->isSensitiveName($name))->toBe($sensitive);
})->with([
    ['payment.card_number', true],
    ['payment[card_number]', true],
    ['guests[0][passport]', false],
    ['client_secret', true],
    ['guests[0][name]', false],
]);

it('masks url credentials even without key rules', function (): void {
    expect((new Redactor([], []))->string('https://bob:pw@host'))->toBe('https://[REDACTED]@host');
});

it('fails closed when a pattern cannot run', function (): void {
    $jit = ini_get('pcre.jit');
    $limit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '1');

    try {
        expect(redactor()->string('<a><password>secret</password></a>'))->toBe(Redactor::UNREDACTABLE);
    } finally {
        ini_set('pcre.jit', (string) $jit);
        ini_set('pcre.backtrack_limit', (string) $limit);
    }
});

it('returns empty text unchanged, e.g. an exception without a message', function (): void {
    expect(redactor()->string(''))->toBe('');
});
