<?php

namespace LiturgicalCalendar\Tests;

use PHPUnit\Framework\TestCase;
use LiturgicalCalendar\Api\JsonFormatter;

/**
 * Unit tests for the JsonFormatter class.
 * Tests that simple string arrays are collapsed to compact form.
 */
class JsonFormatterTest extends TestCase
{
    /**
     * Test that single-element string arrays are collapsed.
     */
    public function testCollapsesSingleElementArray(): void
    {
        $data = [
            'event_key' => 'HolyThurs',
            'color'     => ['white']
        ];

        $result = JsonFormatter::encode($data);

        $this->assertStringContainsString('"color": [ "white" ]', $result);
        $this->assertStringNotContainsString("\"color\": [\n", $result);
    }

    /**
     * Test that multi-element string arrays are collapsed.
     */
    public function testCollapsesMultiElementArray(): void
    {
        $data = [
            'event_key' => 'Advent3',
            'color'     => ['rose', 'purple']
        ];

        $result = JsonFormatter::encode($data);

        $this->assertStringContainsString('"color": [ "rose", "purple" ]', $result);
    }

    /**
     * Test that empty arrays remain on one line.
     */
    public function testEmptyArraysRemainCompact(): void
    {
        $data = [
            'event_key' => 'Test',
            'common'    => []
        ];

        $result = JsonFormatter::encode($data);

        // Empty arrays should stay as []
        $this->assertStringContainsString('"common": []', $result);
    }

    /**
     * Test that nested objects are not collapsed.
     */
    public function testNestedObjectsNotCollapsed(): void
    {
        $data = [
            'litcal' => [
                [
                    'event_key' => 'Test',
                    'grade'     => 7
                ]
            ]
        ];

        $result = JsonFormatter::encode($data);

        // The nested object should still be expanded, not on one line
        $this->assertStringContainsString("\"litcal\": [\n", $result);
    }

    /**
     * Test that unicode characters are unescaped by default.
     */
    public function testUnicodeUnescapedByDefault(): void
    {
        $data = [
            'name' => 'Diocèse de Paris'
        ];

        $result = JsonFormatter::encode($data);

        // With unescapeUnicode = true (default), Unicode chars appear literally
        $this->assertStringContainsString('Diocèse de Paris', $result);
        $this->assertStringContainsString('è', $result);
    }

    /**
     * Test that unicode escaping can be enabled.
     */
    public function testUnicodeEscapingCanBeEnabled(): void
    {
        $data = [
            'name' => 'Diocèse'
        ];

        $result = JsonFormatter::encode($data, false); // unescapeUnicode = false

        // With unescapeUnicode = false, special chars get escaped as \uXXXX
        $this->assertStringContainsString('\u00e8', $result); // è is escaped
        $this->assertStringNotContainsString('è', $result);
    }

    /**
     * Test that strings with escaped characters in arrays are handled.
     */
    public function testArraysWithEscapedStrings(): void
    {
        $data = [
            'values' => ['test "quoted" value', 'another']
        ];

        $result = JsonFormatter::encode($data);

        $decoded = json_decode($result, true);
        $this->assertSame(['test "quoted" value', 'another'], $decoded['values']);
    }

    /**
     * Test real-world temporale data structure.
     */
    public function testTemporaleDataStructure(): void
    {
        $data = [
            [
                'event_key' => 'HolyThurs',
                'grade'     => 7,
                'type'      => 'mobile',
                'color'     => ['white']
            ],
            [
                'event_key' => 'Advent3',
                'grade'     => 7,
                'type'      => 'mobile',
                'color'     => ['rose', 'purple']
            ]
        ];

        $result = JsonFormatter::encode($data);

        $this->assertStringContainsString('"color": [ "white" ]', $result);
        $this->assertStringContainsString('"color": [ "rose", "purple" ]', $result);

        // Verify it's still valid JSON
        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
    }

    /**
     * Test that JsonException is thrown on encoding error.
     */
    public function testThrowsJsonExceptionOnError(): void
    {
        // Create a resource that can't be encoded
        $resource = fopen('php://memory', 'r');

        $this->expectException(\JsonException::class);

        try {
            JsonFormatter::encode($resource);
        } finally {
            fclose($resource);
        }
    }
}
