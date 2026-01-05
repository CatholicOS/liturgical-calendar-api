<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Routes\ReadWrite;

use LiturgicalCalendar\Tests\ApiTestCase;

/**
 * Integration tests for authenticated write operations on the /temporale API endpoint.
 *
 * These tests verify JWT authentication and validation for PUT, PATCH, and DELETE operations.
 * To avoid modifying production data, tests focus on validation errors and non-existent resources.
 *
 * @group slow
 */
final class TemporaleTest extends ApiTestCase
{
    /**
     * Test that unauthenticated DELETE returns 401.
     */
    public function testDeleteTemporaleWithoutAuthReturns401(): void
    {
        $response = self::$http->delete('/temporale/Easter', [
            'http_errors' => false
        ]);
        $this->assertSame(
            401,
            $response->getStatusCode(),
            'DELETE without authentication should return 401 Unauthorized'
        );
    }

    /**
     * Test that unauthenticated PUT returns 401.
     */
    public function testPutTemporaleWithoutAuthReturns401(): void
    {
        $response = self::$http->put('/temporale', [
            'http_errors' => false,
            'json'        => []
        ]);
        $this->assertSame(
            401,
            $response->getStatusCode(),
            'PUT without authentication should return 401 Unauthorized'
        );
    }

    /**
     * Test that unauthenticated PATCH returns 401.
     */
    public function testPatchTemporaleWithoutAuthReturns401(): void
    {
        $response = self::$http->patch('/temporale', [
            'http_errors' => false,
            'json'        => []
        ]);
        $this->assertSame(
            401,
            $response->getStatusCode(),
            'PATCH without authentication should return 401 Unauthorized'
        );
    }

    /**
     * Test that authenticated PUT with invalid payload (not an array) returns 400.
     */
    public function testAuthenticatedPutWithInvalidPayloadReturns400(): void
    {
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        $response = self::$http->put('/temporale', [
            'headers'     => array_merge(
                self::authHeaders($token),
                ['Content-Type' => 'application/json']
            ),
            'body'        => json_encode(['not' => 'an array of events']),
            'http_errors' => false
        ]);

        $this->assertSame(
            400,
            $response->getStatusCode(),
            'PUT with object instead of array should return 400'
        );
    }

    /**
     * Test that authenticated PUT with empty event_key returns 400.
     */
    public function testAuthenticatedPutWithEmptyEventKeyReturns400(): void
    {
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        $invalidPayload = [
            [
                'event_key' => '',
                'grade'     => 3,
                'type'      => 'mobile',
                'color'     => ['white']
            ]
        ];

        $response = self::$http->put('/temporale', [
            'headers'     => array_merge(
                self::authHeaders($token),
                ['Content-Type' => 'application/json']
            ),
            'body'        => json_encode($invalidPayload),
            'http_errors' => false
        ]);

        $this->assertSame(
            400,
            $response->getStatusCode(),
            'PUT with empty event_key should return 400'
        );
    }

    /**
     * Test that authenticated PUT with invalid event structure returns 400.
     */
    public function testAuthenticatedPutWithInvalidEventReturns400(): void
    {
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        // Missing required properties
        $response = self::$http->put('/temporale', [
            'headers'     => array_merge(
                self::authHeaders($token),
                ['Content-Type' => 'application/json']
            ),
            'body'        => json_encode([['event_key' => 'Test']]),
            'http_errors' => false
        ]);

        $this->assertSame(
            400,
            $response->getStatusCode(),
            'PUT with incomplete event should return 400'
        );
    }

    /**
     * Test that authenticated PUT with duplicate event_keys returns 400.
     */
    public function testAuthenticatedPutWithDuplicateEventKeysReturns400(): void
    {
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        $duplicatePayload = [
            [
                'event_key' => 'DuplicateTest',
                'grade'     => 3,
                'type'      => 'mobile',
                'color'     => ['white']
            ],
            [
                'event_key' => 'DuplicateTest',
                'grade'     => 4,
                'type'      => 'fixed',
                'color'     => ['red']
            ]
        ];

        $response = self::$http->put('/temporale', [
            'headers'     => array_merge(
                self::authHeaders($token),
                ['Content-Type' => 'application/json']
            ),
            'body'        => json_encode($duplicatePayload),
            'http_errors' => false
        ]);

        $this->assertSame(
            400,
            $response->getStatusCode(),
            'PUT with duplicate event_keys should return 400'
        );
    }

    /**
     * Test that authenticated PUT with invalid grade (out of range) returns 400.
     */
    public function testAuthenticatedPutWithInvalidGradeReturns400(): void
    {
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        $invalidPayload = [
            [
                'event_key' => 'TestEvent',
                'grade'     => 99,  // Invalid: should be 0-7
                'type'      => 'mobile',
                'color'     => ['white']
            ]
        ];

        $response = self::$http->put('/temporale', [
            'headers'     => array_merge(
                self::authHeaders($token),
                ['Content-Type' => 'application/json']
            ),
            'body'        => json_encode($invalidPayload),
            'http_errors' => false
        ]);

        $this->assertSame(
            400,
            $response->getStatusCode(),
            'PUT with invalid grade should return 400'
        );
    }

    /**
     * Test that authenticated PUT with invalid color returns 400.
     */
    public function testAuthenticatedPutWithInvalidColorReturns400(): void
    {
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        $invalidPayload = [
            [
                'event_key' => 'TestEvent',
                'grade'     => 3,
                'type'      => 'mobile',
                'color'     => ['invalid_color']
            ]
        ];

        $response = self::$http->put('/temporale', [
            'headers'     => array_merge(
                self::authHeaders($token),
                ['Content-Type' => 'application/json']
            ),
            'body'        => json_encode($invalidPayload),
            'http_errors' => false
        ]);

        $this->assertSame(
            400,
            $response->getStatusCode(),
            'PUT with invalid color should return 400'
        );
    }

    /**
     * Test that authenticated PATCH with invalid event structure returns 400.
     */
    public function testAuthenticatedPatchWithInvalidEventReturns400(): void
    {
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        // Event without event_key
        $response = self::$http->patch('/temporale', [
            'headers'     => array_merge(
                self::authHeaders($token),
                ['Content-Type' => 'application/json']
            ),
            'body'        => json_encode([['grade' => 3, 'type' => 'mobile', 'color' => ['white']]]),
            'http_errors' => false
        ]);

        $this->assertSame(
            400,
            $response->getStatusCode(),
            'PATCH with event missing event_key should return 400'
        );
    }

    /**
     * Test that authenticated PATCH with duplicate event_keys returns 400.
     */
    public function testAuthenticatedPatchWithDuplicateEventKeysReturns400(): void
    {
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        $duplicatePayload = [
            [
                'event_key' => 'DuplicateTest',
                'grade'     => 3,
                'type'      => 'mobile',
                'color'     => ['white']
            ],
            [
                'event_key' => 'DuplicateTest',
                'grade'     => 4,
                'type'      => 'fixed',
                'color'     => ['red']
            ]
        ];

        $response = self::$http->patch('/temporale', [
            'headers'     => array_merge(
                self::authHeaders($token),
                ['Content-Type' => 'application/json']
            ),
            'body'        => json_encode($duplicatePayload),
            'http_errors' => false
        ]);

        $this->assertSame(
            400,
            $response->getStatusCode(),
            'PATCH with duplicate event_keys should return 400'
        );
    }

    /**
     * Test that authenticated DELETE for non-existent event returns 404.
     */
    public function testAuthenticatedDeleteNonExistentEventReturns404(): void
    {
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        $response = self::$http->delete('/temporale/NonExistentEventKey12345', [
            'headers'     => self::authHeaders($token),
            'http_errors' => false
        ]);

        $this->assertSame(
            404,
            $response->getStatusCode(),
            'DELETE for non-existent event_key should return 404'
        );
    }

    /**
     * Test that authenticated PUT/PATCH without Content-Type returns 415.
     */
    public function testAuthenticatedWriteWithoutContentTypeReturns415(): void
    {
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        // PUT without Content-Type
        $putResponse = self::$http->put('/temporale', [
            'headers'     => self::authHeaders($token),
            'body'        => '[]',
            'http_errors' => false
        ]);
        $this->assertSame(
            415,
            $putResponse->getStatusCode(),
            'PUT without Content-Type should return 415'
        );

        // PATCH without Content-Type
        $patchResponse = self::$http->patch('/temporale', [
            'headers'     => self::authHeaders($token),
            'body'        => '[]',
            'http_errors' => false
        ]);
        $this->assertSame(
            415,
            $patchResponse->getStatusCode(),
            'PATCH without Content-Type should return 415'
        );
    }
}
