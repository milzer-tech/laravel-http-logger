<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Core\Redaction;

use Milzer\HttpLogger\Core\LoggingOptions;
use UnexpectedValueException;

/**
 * Masks sensitive values in headers, structured bodies and raw (XML/JSON/form) strings.
 *
 * Keys are compared after normalisation (lower-case, separators removed), so the rule
 * "card_number" matches "cardNumber", "Card-Number" and "CARD_NUMBER". "*" is a wildcard.
 */
final class Redactor
{
    /**
     * Returned instead of a body whose masking could not run (e.g. PCRE limits on a huge body):
     * logging nothing is safer than logging something unmasked.
     */
    public const UNREDACTABLE = '[body omitted: could not be redacted safely]';

    private const JSON_PAIR = '/"((?:[^"\\\\]|\\\\.){1,128})"(\s*:\s*)("(?:[^"\\\\]|\\\\.)*"|-?\d[\d.eE+\-]*|true|false|null)/';

    /** An element with its content (text, CDATA or child elements) up to its closing tag; not self-closing. */
    private const XML_ELEMENT = '/<((?:[\w.\-]+:)?([\w.\-]+))(\s[^<>]*)?(?<!\/)>(.*?)<\/\1\s*>/s';

    private const URL_CREDENTIALS = '#\b([a-z][a-z0-9+.\-]*://)[^\s/?\#@"\'<>]+@#i';

    private const XML_ATTRIBUTE = '/(\s(?:[\w.\-]+:)?([\w.\-]+)\s*=\s*)("[^"]*"|\'[^\']*\')/';

    /** key=value in query strings and free text; values starting with a quote are XML attributes, handled above. */
    private const FORM_PAIR = '/(^|[?&;\s])([^=&;\s"\'<>]{1,128})=(?!["\'])([^&;\s]*)/';

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

    public static function fromOptions(LoggingOptions $options): self
    {
        return new self($options->redactHeaders, $options->redactKeys, $options->redactionMask);
    }

    /**
     * Best-effort masking inside raw text that could not be decoded into an array: credentials in
     * URLs, XML elements (including ones with child elements) and attributes, JSON "key": value
     * pairs and form-encoded key=value pairs.
     */
    public function string(string $body): string
    {
        if ($body === '') {
            return $body;
        }

        try {
            $body = $this->replace(self::URL_CREDENTIALS, $this->replaceUrlCredentials(...), $body);

            if ($this->keyPattern === null) {
                return $body;
            }

            $body = $this->replace(self::XML_ELEMENT, $this->replaceXmlElement(...), $body);
            $body = $this->replace(self::XML_ATTRIBUTE, $this->replaceXmlAttribute(...), $body);
            $body = $this->replace(self::JSON_PAIR, $this->replaceJsonPair(...), $body);

            return $this->replace(self::FORM_PAIR, $this->replaceFormPair(...), $body);
        } catch (UnexpectedValueException) {
            return self::UNREDACTABLE;
        }
    }

    public function isSensitiveKey(string $key): bool
    {
        return $this->cache[$key] ??= $this->matches($this->keyPattern, $key);
    }

    /**
     * For flattened field names such as "payment.card_number" or "payment[card_number]":
     * sensitive when the whole name or any of its segments is.
     */
    public function isSensitiveName(string $name): bool
    {
        if ($this->isSensitiveKey($name)) {
            return true;
        }

        foreach (preg_split('/[.\[\]]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $segment) {
            if ($this->isSensitiveKey($segment)) {
                return true;
            }
        }

        return false;
    }

    public function mask(): string
    {
        return $this->mask;
    }

    /**
     * @param  callable(array<int|string, string>): string  $callback
     *
     * @throws UnexpectedValueException When the pattern cannot run, so callers can fail closed.
     */
    private function replace(string $pattern, callable $callback, string $subject): string
    {
        return preg_replace_callback($pattern, $callback, $subject)
            ?? throw new UnexpectedValueException(preg_last_error_msg());
    }

    /**
     * @param  array<int|string, string>  $m  [whole, scheme://]
     */
    private function replaceUrlCredentials(array $m): string
    {
        return $m[1].$this->mask.'@';
    }

    /**
     * A sensitive element loses its whole content, child elements included. Other elements are
     * searched recursively, so a sensitive element anywhere inside them is still found.
     *
     * @param  array<int|string, string>  $m  [whole, qualified name, local name, attributes, contents]
     */
    private function replaceXmlElement(array $m): string
    {
        $open = '<'.$m[1].$m[3].'>';
        $close = '</'.$m[1].'>';

        if ($this->isSensitiveKey($m[2])) {
            return $open.htmlspecialchars($this->mask, ENT_XML1).$close;
        }

        if (! str_contains($m[4], '<')) {
            return $m[0];
        }

        return $open.$this->replace(self::XML_ELEMENT, $this->replaceXmlElement(...), $m[4]).$close;
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
