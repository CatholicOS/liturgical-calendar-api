<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api;

/**
 * JSON formatter that preserves compact array formatting.
 *
 * PHP's JSON_PRETTY_PRINT expands all arrays to multiple lines, but source
 * files use compact arrays like ["white"] on a single line. This class
 * collapses simple string arrays back to compact form after encoding.
 */
final class JsonFormatter
{
    /**
     * Encode data as JSON with pretty-printing and compact simple arrays.
     *
     * @param mixed $data            The data to encode
     * @param bool  $unescapeUnicode Whether to use JSON_UNESCAPED_UNICODE (default: true)
     *
     * @return string The formatted JSON string
     *
     * @throws \JsonException If encoding fails
     */
    public static function encode(mixed $data, bool $unescapeUnicode = true): string
    {
        $flags = JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR;
        if ($unescapeUnicode) {
            $flags |= JSON_UNESCAPED_UNICODE;
        }

        $json = json_encode($data, $flags);

        // Collapse simple string arrays: [\n    "value"\n] -> [ "value" ]
        // Matches arrays containing only quoted strings (with possible escapes)
        // Uses possessive quantifiers (*+) to prevent ReDoS via catastrophic backtracking
        // Uses [ \t] for horizontal whitespace to avoid consuming newlines prematurely
        return preg_replace_callback(
            '/\[[ \t]*+\n[ \t]*+("(?:[^"\\\\]|\\\\.)*+"(?:[ \t]*+,[ \t]*+\n[ \t]*+"(?:[^"\\\\]|\\\\.)*+")*+)[ \t]*+\n[ \t]*+\]/s',
            fn(array $m): string => '[ ' . preg_replace('/[ \t]*+\n[ \t]*+/', ' ', $m[1]) . ' ]',
            $json
        ) ?? $json;
    }
}
