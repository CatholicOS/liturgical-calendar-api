<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Routes\Readonly;

use LiturgicalCalendar\Tests\ApiTestCase;

final class TemporaleTest extends ApiTestCase
{
    public function testGetTemporaleReturnsJson(): void
    {
        $response = self::$http->get('/temporale', []);
        $this->assertSame(
            200,
            $response->getStatusCode(),
            'Expected HTTP 200 OK, got ' . $response->getStatusCode() . ': ' . $response->getBody()
        );
        $this->assertStringStartsWith(
            'application/json',
            $response->getHeaderLine('Content-Type'),
            'Expected Content-Type application/json, got ' . $response->getHeaderLine('Content-Type')
        );
    }

    public function testGetTemporaleReturnsArrayOfEvents(): void
    {
        $response = self::$http->get('/temporale', []);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getBody());
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Invalid JSON: ' . json_last_error_msg());
        $this->assertIsArray($data, 'Response should be a JSON array');
        $this->assertNotEmpty($data, 'Response array should not be empty');
    }

    public function testGetTemporaleEventStructure(): void
    {
        $response = self::$http->get('/temporale', []);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getBody());
        $this->assertIsArray($data);

        // Check at least one event has the expected structure
        $event = $data[0];
        $this->assertIsObject($event, 'Each event should be an object');
        $this->assertObjectHasProperty('event_key', $event, 'Event should have event_key property');
        $this->assertIsString($event->event_key, 'event_key should be a string');
        $this->assertObjectHasProperty('grade', $event, 'Event should have grade property');
        $this->assertIsInt($event->grade, 'grade should be an integer');
        $this->assertObjectHasProperty('type', $event, 'Event should have type property');
        $this->assertIsString($event->type, 'type should be a string');
        $this->assertContains($event->type, ['mobile', 'fixed'], 'type should be either "mobile" or "fixed"');
        $this->assertObjectHasProperty('color', $event, 'Event should have color property');
        $this->assertIsArray($event->color, 'color should be an array');
    }

    public function testGetTemporaleReturnsLocaleHeader(): void
    {
        $response = self::$http->get('/temporale', []);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(
            $response->hasHeader('X-Litcal-Temporale-Locale'),
            'Response should have X-Litcal-Temporale-Locale header'
        );
    }

    public function testGetTemporaleWithEnglishLocale(): void
    {
        $response = self::$http->get('/temporale', [
            'headers' => ['Accept-Language' => 'en']
        ]);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getBody());
        $this->assertIsArray($data);

        // Find Easter event and check it has a translated name
        $easter = array_find($data, fn($event) => $event->event_key === 'Easter');
        $this->assertNotNull($easter, 'Easter event should exist');
        $this->assertObjectHasProperty('name', $easter, 'Event should have translated name');
        $this->assertIsString($easter->name, 'name should be a string');
    }

    public function testGetTemporaleWithLatinLocale(): void
    {
        $response = self::$http->get('/temporale', [
            'headers' => ['Accept-Language' => 'la']
        ]);
        $this->assertSame(200, $response->getStatusCode());

        $localeHeader = $response->getHeaderLine('X-Litcal-Temporale-Locale');
        $this->assertSame('la', $localeHeader, 'Locale header should be "la"');

        $data = json_decode((string) $response->getBody());
        $this->assertIsArray($data);
    }

    public function testGetTemporaleWithItalianLocale(): void
    {
        $response = self::$http->get('/temporale', [
            'headers' => ['Accept-Language' => 'it']
        ]);
        $this->assertSame(200, $response->getStatusCode());

        $localeHeader = $response->getHeaderLine('X-Litcal-Temporale-Locale');
        $this->assertSame('it', $localeHeader, 'Locale header should be "it"');
    }

    public function testGetTemporaleContainsKnownEvents(): void
    {
        $response = self::$http->get('/temporale', []);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getBody());
        $this->assertIsArray($data);

        // Extract event keys
        $eventKeys = array_map(fn($event) => $event->event_key, $data);

        // Check for known temporale events
        $knownEvents = ['Easter', 'Christmas', 'Pentecost', 'Advent1', 'Lent1', 'HolyThurs', 'GoodFri'];
        foreach ($knownEvents as $knownEvent) {
            $this->assertContains(
                $knownEvent,
                $eventKeys,
                "Temporale should contain the event '{$knownEvent}'"
            );
        }
    }

    public function testGetTemporaleWithUnsupportedAcceptDefaultsToJson(): void
    {
        // LAX acceptability level allows unsupported Accept headers and defaults to JSON
        $response = self::$http->get('/temporale', [
            'headers' => ['Accept' => 'text/plain']
        ]);
        $this->assertSame(
            200,
            $response->getStatusCode(),
            'Expected HTTP 200 OK even with unsupported Accept header (LAX mode)'
        );
        $this->assertStringStartsWith(
            'application/json',
            $response->getHeaderLine('Content-Type'),
            'Should default to JSON when Accept header is unsupported'
        );
    }

    public function testGetTemporalePostMethodWorks(): void
    {
        $response = self::$http->post('/temporale', []);
        $this->assertSame(
            200,
            $response->getStatusCode(),
            'POST method should work the same as GET'
        );

        $data = json_decode((string) $response->getBody());
        $this->assertIsArray($data, 'Response should be a JSON array');
    }
}
