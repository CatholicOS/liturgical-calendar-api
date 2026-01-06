<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Routes\Readonly;

use LiturgicalCalendar\Tests\ApiTestCase;

/**
 * Integration tests for the /temporale API endpoint.
 *
 * @group slow
 */
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

    public function testGetTemporaleReturnsObjectWithEvents(): void
    {
        $response = self::$http->get('/temporale', []);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getBody());
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Invalid JSON: ' . json_last_error_msg());
        $this->assertIsObject($data, 'Response should be a JSON object');
        $this->assertObjectHasProperty('events', $data, 'Response should have events property');
        $this->assertObjectHasProperty('locale', $data, 'Response should have locale property');
        $this->assertIsArray($data->events, 'events should be an array');
        $this->assertNotEmpty($data->events, 'events array should not be empty');
        $this->assertIsString($data->locale, 'locale should be a string');
    }

    public function testGetTemporaleEventStructure(): void
    {
        $response = self::$http->get('/temporale', []);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getBody());
        $this->assertIsObject($data);
        $this->assertObjectHasProperty('events', $data);
        $this->assertNotEmpty($data->events, 'Expected at least one event in response');

        // Check at least one event has the expected structure
        $event = $data->events[0];
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
        $this->assertIsObject($data);
        $this->assertObjectHasProperty('events', $data);

        // Find Easter event and check it has a translated name
        $easter = array_find($data->events, fn($event) => $event->event_key === 'Easter');
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
        $this->assertIsObject($data);
        $this->assertObjectHasProperty('events', $data);

        // Validate translated content
        $easter = array_find($data->events, fn($event) => $event->event_key === 'Easter');
        $this->assertNotNull($easter, 'Easter event should exist');
        $this->assertObjectHasProperty('name', $easter, 'Event should have translated name');
        $this->assertIsString($easter->name, 'name should be a string');
    }

    public function testGetTemporaleWithItalianLocale(): void
    {
        $response = self::$http->get('/temporale', [
            'headers' => ['Accept-Language' => 'it']
        ]);
        $this->assertSame(200, $response->getStatusCode());

        $localeHeader = $response->getHeaderLine('X-Litcal-Temporale-Locale');
        $this->assertSame('it', $localeHeader, 'Locale header should be "it"');

        $data = json_decode((string) $response->getBody());
        $this->assertIsObject($data);
        $this->assertObjectHasProperty('events', $data);

        // Validate translated content
        $easter = array_find($data->events, fn($event) => $event->event_key === 'Easter');
        $this->assertNotNull($easter, 'Easter event should exist');
        $this->assertObjectHasProperty('name', $easter, 'Event should have translated name');
        $this->assertIsString($easter->name, 'name should be a string');
    }

    public function testGetTemporaleContainsKnownEvents(): void
    {
        $response = self::$http->get('/temporale', []);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getBody());
        $this->assertIsObject($data);
        $this->assertObjectHasProperty('events', $data);

        // Extract event keys
        $eventKeys = array_map(fn($event) => $event->event_key, $data->events);

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
        $this->assertIsObject($data, 'Response should be a JSON object');
        $this->assertObjectHasProperty('events', $data, 'Response should have events property');
        $this->assertIsArray($data->events, 'events should be an array');
    }

    public function testGetTemporaleWithUnsupportedLocaleDefaultsToLatin(): void
    {
        // Unsupported locale should default to Latin (la)
        $response = self::$http->get('/temporale', [
            'headers' => ['Accept-Language' => 'xyz']
        ]);
        $this->assertSame(200, $response->getStatusCode());

        $localeHeader = $response->getHeaderLine('X-Litcal-Temporale-Locale');
        $this->assertSame('la', $localeHeader, 'Unsupported locale should default to Latin (la)');
    }

    public function testGetTemporaleWithLocaleQueryParameter(): void
    {
        $response = self::$http->get('/temporale', [
            'query' => ['locale' => 'it']
        ]);
        $this->assertSame(200, $response->getStatusCode());

        $localeHeader = $response->getHeaderLine('X-Litcal-Temporale-Locale');
        $this->assertSame('it', $localeHeader, 'Locale query parameter should set locale to "it"');

        $data = json_decode((string) $response->getBody());
        $this->assertIsObject($data);
        $this->assertObjectHasProperty('locale', $data);
        $this->assertSame('it', $data->locale, 'Response locale should be "it"');
    }

    public function testLocaleQueryParameterOverridesAcceptLanguageHeader(): void
    {
        // Accept-Language says French, but query param says Italian
        $response = self::$http->get('/temporale', [
            'headers' => ['Accept-Language' => 'fr'],
            'query'   => ['locale' => 'it']
        ]);
        $this->assertSame(200, $response->getStatusCode());

        $localeHeader = $response->getHeaderLine('X-Litcal-Temporale-Locale');
        $this->assertSame('it', $localeHeader, 'Locale query parameter should override Accept-Language header');

        $data = json_decode((string) $response->getBody());
        $this->assertIsObject($data);
        $this->assertObjectHasProperty('locale', $data);
        $this->assertSame('it', $data->locale, 'Response locale should be "it" from query param, not "fr" from header');
    }

    public function testInvalidLocaleQueryParameterReturns400(): void
    {
        // Test malformed/syntactically invalid locale code
        $response = self::$http->get('/temporale', [
            'query'       => ['locale' => 'invalid_locale_xyz'],
            'http_errors' => false
        ]);
        $this->assertSame(
            400,
            $response->getStatusCode(),
            'Invalid locale query parameter should return 400 Bad Request'
        );
    }

    public function testUnavailableLocaleQueryParameterReturns400(): void
    {
        // 'zh' is a valid locale code but not available for temporale data
        $response = self::$http->get('/temporale', [
            'query'       => ['locale' => 'zh'],
            'http_errors' => false
        ]);
        $this->assertSame(
            400,
            $response->getStatusCode(),
            'Unavailable locale query parameter should return 400 Bad Request'
        );
    }
}
