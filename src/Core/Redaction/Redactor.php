<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Core\Redaction;

/**
 * Masks sensitive values in headers, structured bodies and raw (XML/JSON/form) strings.
 *
 * Keys are compared after normalisation (lower-case, separators removed), so the rule
 * "card_number" matches "cardNumber", "Card-Number" and "CARD_NUMBER". "*" is a wildcard.
 */
final class Redactor
{
    private const JSON_PAIR = '/"((?:[^"\\\\]|\\\\.){1,128})"(\s*:\s*)("(?:[^"\\\\]|\\\\.)*"|-?\d[\d.eE+\-]*|true|false|null)/';

    private const XML_ELEMENT = '/<((?:[\w.\-]+:)?([\w.\-]+))(\s[^<>]*)?>(<!\[CDATA\[.*?\]\]>|[^<]*)<\/\1\s*>/s';

    private const XML_ATTRIBUTE = '/(\s(?:[\w.\-]+:)?([\w.\-]+)\s*=\s*)("[^"]*"|\'[^\']*\')/';

    private const FORM_PAIR = '/(^|[?&;])([^=&;\s]{1,128})=([^&;\s]*)/';

    private readonly ?string $headerPattern;

    private readonly ?string $keyPattern;

    /** @var array<string, bool> */
    private array $cache = [];

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $keys
     */
    public function __construct(
        array $headers,
        array $keys,
        private readonly string $mask = '[REDACTED]',
    ) {
        $this->headerPattern = $this->compile($headers);
        $this->keyPattern = $this->compile($keys);
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     * @return array<string, string|list<string>>
     */
    public function headers(array $headers): array
    {
        foreach ($headers as $name => $value) {
            if ($this->matches($this->headerPattern, $name)) {
                $headers[$name] = $this->mask;
            }
        }

        return $headers;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function array(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $data[$key] = $this->mask;
            } elseif (is_array($value)) {
                $data[$key] = $this->array($value);
            }
        }

        return $data;
    }

    /**
     * Best-effort masking inside raw payloads that could not be decoded into an array:
     * XML elements and attributes, JSON "key": value pairs and form-encoded key=value pairs.
     */
    public function string(string $body): string
    {
        if ($this->keyPattern === null || $body === '') {
            return $body;
        }

        $replacements = [
            self::XML_ELEMENT => $this->replaceXmlElement(...),
            self::XML_ATTRIBUTE => $this->replaceXmlAttribute(...),
            self::JSON_PAIR => $this->replaceJsonPair(...),
            self::FORM_PAIR => $this->replaceFormPair(...),
        ];

        foreach ($replacements as $pattern => $callback) {
            $body = preg_replace_callback($pattern, $callback, $body) ?? $body;
        }

        return $body;
    }

    public function isSensitiveKey(string $key): bool
    {
        return $this->cache[$key] ??= $this->matches($this->keyPattern, $key);
    }

    public function mask(): string
    {
        return $this->mask;
    }

    /**
     * @param  array<int|string, string>  $m  [whole, qualified name, local name, attributes, contents]
     */
    private function replaceXmlElement(array $m): string
    {
        if (! $this->isSensitiveKey($m[2])) {
            return $m[0];
        }

        return '<'.$m[1].($m[3] ?? '').'>'.htmlspecialchars($this->mask, ENT_XML1).'</'.$m[1].'>';
    }

    /**
     * @param  array<int|string, string>  $m  [whole, prefix incl. "=", local name, quoted value]
     */
    private function replaceXmlAttribute(array $m): string
    {
        if (! $this->isSensitiveKey($m[2])) {
            return $m[0];
        }

        $quote = $m[3][0];

        return $m[1].$quote.htmlspecialchars($this->mask, ENT_XML1 | ENT_QUOTES).$quote;
    }

    /**
     * @param  array<int|string, string>  $m  [whole, key, separator, value]
     */
    private function replaceJsonPair(array $m): string
    {
        if (! $this->isSensitiveKey(stripcslashes($m[1]))) {
            return $m[0];
        }

        return '"'.$m[1].'"'.$m[2].json_encode($this->mask);
    }

    /**
     * @param  array<int|string, string>  $m  [whole, delimiter, key, value]
     */
    private function replaceFormPair(array $m): string
    {
        if (! $this->isSensitiveKey(urldecode($m[2]))) {
            return $m[0];
        }

        return $m[1].$m[2].'='.rawurlencode($this->mask);
    }

    private function matches(?string $pattern, string $key): bool
    {
        return $pattern !== null && preg_match($pattern, $this->normalize($key)) === 1;
    }

    /**
     * @param  list<string>  $rules
     */
    private function compile(array $rules): ?string
    {
        $alternatives = [];

        foreach ($rules as $rule) {
            $normalized = $this->normalize($rule, keepWildcards: true);

            if ($normalized !== '' && $normalized !== '*') {
                $alternatives[] = str_replace('\*', '.*', preg_quote($normalized, '/'));
            }
        }

        return $alternatives === [] ? null : '/^(?:'.implode('|', array_unique($alternatives)).')$/';
    }

    private function normalize(string $key, bool $keepWildcards = false): string
    {
        return (string) preg_replace($keepWildcards ? '/[^a-z0-9*]/' : '/[^a-z0-9]/', '', strtolower($key));
    }
}
