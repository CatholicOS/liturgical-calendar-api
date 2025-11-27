<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Tests\Schemas;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Swaggest\JsonSchema\Schema;
use Swaggest\JsonSchema\InvalidValue;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Router;

/**
 * Test suite for validating frontend payloads against JSON schemas.
 *
 * NOTE: This test intentionally extends TestCase rather than ApiTestCase.
 * Unlike integration tests in phpunit_tests/Routes/ that make HTTP requests
 * to a running API, this is a unit test that validates JSON payloads against
 * schemas locally using Swaggest\JsonSchema. It does not require the API
 * server to be running and should execute quickly without network overhead.
 *
 * These tests ensure that:
 * 1. Sample payloads (representing what the frontend should produce) validate against schemas
 * 2. Invalid payloads (like the broken serialization format) are correctly rejected
 * 3. Frontend-backend contract is maintained
 *
 * The fixtures in phpunit_tests/fixtures/payloads/ represent:
 * - valid_*.json: Payloads that should pass schema validation (frontend contract)
 * - invalid_*.json: Payloads that should fail schema validation (e.g., broken serialization)
 */
#[Group('Schemas')]
class PayloadValidationTest extends TestCase
{
    private const FIXTURES_PATH = __DIR__ . '/../fixtures/payloads';

    private static bool $routerInitialized = false;

    /**
     * Ensure Router paths are initialized.
     *
     * This is needed because:
     * 1. Data providers run before setUpBeforeClass()
     * 2. LitSchema::path() requires Router paths to be initialized
     *
     * Router::getApiPaths() is idempotent, but we use a flag to avoid
     * unnecessary repeated calls.
     */
    private static function ensureRouterInitialized(): void
    {
        if (!self::$routerInitialized) {
            Router::getApiPaths();
            self::$routerInitialized = true;
        }
    }

    protected function setUp(): void
    {
        self::ensureRouterInitialized();
    }

    /**
     * Load a JSON fixture file.
     *
     * @param string $filename The fixture filename (relative to fixtures/payloads/)
     * @return \stdClass The parsed JSON data
     */
    private static function loadFixture(string $filename): \stdClass
    {
        $path = self::FIXTURES_PATH . '/' . $filename;
        if (!file_exists($path)) {
            throw new \RuntimeException("Fixture file not found: $path");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Failed to read fixture file: $path");
        }

        $data = json_decode($content);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Failed to parse JSON in fixture: $path - " . json_last_error_msg());
        }

        if (!$data instanceof \stdClass) {
            throw new \RuntimeException('Fixture must be a JSON object, not ' . gettype($data) . ': ' . $path);
        }

        return $data;
    }

    /**
     * Data provider for valid diocesan calendar payloads.
     *
     * @return array<string, array{0: string}>
     */
    public static function validDiocesanPayloadProvider(): array
    {
        return [
            'valid diocesan calendar' => ['valid_diocesan_calendar.json'],
        ];
    }

    /**
     * Data provider for valid national calendar payloads.
     *
     * @return array<string, array{0: string}>
     */
    public static function validNationalPayloadProvider(): array
    {
        return [
            'valid national calendar' => ['valid_national_calendar.json'],
        ];
    }

    /**
     * Data provider for invalid payloads that should be rejected.
     *
     * @return array<string, array{0: string, 1: LitSchema}>
     */
    public static function invalidPayloadProvider(): array
    {
        self::ensureRouterInitialized();

        return [
            'wrapped litcal (broken serialization)' => ['invalid_litcal_wrapped.json', LitSchema::DIOCESAN],
            'missing metadata'                      => ['invalid_missing_metadata.json', LitSchema::DIOCESAN],
        ];
    }

    /**
     * Test that valid diocesan calendar payloads pass schema validation.
     *
     * This verifies the frontend-backend contract for diocesan calendar creation.
     */
    #[DataProvider('validDiocesanPayloadProvider')]
    public function testValidDiocesanPayloadPassesSchemaValidation(string $fixtureFile): void
    {
        $schemaPath = LitSchema::DIOCESAN->path();
        $schema     = Schema::import($schemaPath);

        $payload = self::loadFixture($fixtureFile);

        // This should not throw - valid payloads must pass
        $schema->in($payload);
        $this->assertTrue(true, "Valid diocesan payload should pass schema validation: $fixtureFile");
    }

    /**
     * Test that valid national calendar payloads pass schema validation.
     *
     * This verifies the frontend-backend contract for national calendar creation.
     */
    #[DataProvider('validNationalPayloadProvider')]
    public function testValidNationalPayloadPassesSchemaValidation(string $fixtureFile): void
    {
        $schemaPath = LitSchema::NATIONAL->path();
        $schema     = Schema::import($schemaPath);

        $payload = self::loadFixture($fixtureFile);

        // This should not throw - valid payloads must pass
        $schema->in($payload);
        $this->assertTrue(true, "Valid national payload should pass schema validation: $fixtureFile");
    }

    /**
     * Test that invalid payloads are correctly rejected by schema validation.
     *
     * This ensures that broken serialization formats (like wrapped litcal) are detected.
     */
    #[DataProvider('invalidPayloadProvider')]
    public function testInvalidPayloadFailsSchemaValidation(string $fixtureFile, LitSchema $litSchema): void
    {
        $schemaPath = $litSchema->path();
        $schema     = Schema::import($schemaPath);

        $payload = self::loadFixture($fixtureFile);

        $this->expectException(InvalidValue::class);
        $schema->in($payload);
    }

    /**
     * Test that the litcal property must be an array, not an object with litcalItems.
     *
     * This specifically tests the serialization bug where LitCalItemCollection
     * serializes as {"litcalItems": [...]} instead of [...].
     */
    public function testLitcalMustBeArrayNotObject(): void
    {
        $schemaPath = LitSchema::DIOCESAN->path();
        $schema     = Schema::import($schemaPath);

        // Simulate the broken serialization output
        $brokenPayload = (object) [
            'litcal'   => (object) [
                'litcalItems' => [
                    (object) [
                        'liturgical_event' => (object) [
                            'event_key' => 'TestEvent',
                            'day'       => 1,
                            'month'     => 1,
                            'color'     => ['white'],
                            'grade'     => 3,
                            'common'    => [],
                        ],
                        'metadata'         => (object) [
                            'form_rownum' => 0,
                            'since_year'  => 2020,
                        ],
                    ],
                ],
            ],
            'metadata' => (object) [
                'diocese_id'   => 'newyor_us',
                'diocese_name' => 'Archdiocese of New York (New York)',
                'nation'       => 'US',
                'locales'      => ['en_US'],
                'timezone'     => 'America/New_York',
            ],
        ];

        $this->expectException(InvalidValue::class);
        $schema->in($brokenPayload);
    }

    /**
     * Test that correct litcal array format passes validation.
     *
     * This verifies the expected format after fixing the serialization bug.
     */
    public function testLitcalAsArrayPassesValidation(): void
    {
        $schemaPath = LitSchema::DIOCESAN->path();
        $schema     = Schema::import($schemaPath);

        // Correct format - litcal is an array directly
        $correctPayload = (object) [
            'litcal'   => [
                (object) [
                    'liturgical_event' => (object) [
                        'event_key' => 'TestEvent',
                        'day'       => 1,
                        'month'     => 1,
                        'color'     => ['white'],
                        'grade'     => 3,
                        'common'    => [],
                    ],
                    'metadata'         => (object) [
                        'form_rownum' => 0,
                        'since_year'  => 2020,
                    ],
                ],
            ],
            'metadata' => (object) [
                'diocese_id'   => 'newyor_us',
                'diocese_name' => 'Archdiocese of New York (New York)',
                'nation'       => 'US',
                'locales'      => ['en_US'],
                'timezone'     => 'America/New_York',
            ],
        ];

        // This should not throw
        $schema->in($correctPayload);
        $this->assertTrue(true, 'Correct litcal array format should pass validation');
    }

    /**
     * Test i18n structure validation.
     *
     * Verifies that the i18n property (when present) has the correct structure:
     * { "locale": { "event_key": "translation" } }
     */
    public function testI18nStructureValidation(): void
    {
        $schemaPath = LitSchema::DIOCESAN->path();
        $schema     = Schema::import($schemaPath);

        // Payload with correct i18n structure
        $payload = (object) [
            'litcal'   => [
                (object) [
                    'liturgical_event' => (object) [
                        'event_key' => 'TestEvent',
                        'day'       => 1,
                        'month'     => 1,
                        'color'     => ['white'],
                        'grade'     => 3,
                        'common'    => [],
                    ],
                    'metadata'         => (object) [
                        'form_rownum' => 0,
                        'since_year'  => 2020,
                    ],
                ],
            ],
            'metadata' => (object) [
                'diocese_id'   => 'newyor_us',
                'diocese_name' => 'Archdiocese of New York (New York)',
                'nation'       => 'US',
                'locales'      => ['en_US'],
                'timezone'     => 'America/New_York',
            ],
            'i18n'     => (object) [
                'en_US' => (object) ['TestEvent' => 'Test Event Translation'],
            ],
        ];

        // This should not throw
        $schema->in($payload);
        $this->assertTrue(true, 'Correct i18n structure should pass validation');
    }

    /**
     * Test that a payload matching exactly what the frontend should produce validates.
     *
     * This is a comprehensive test using a complete payload structure.
     */
    public function testCompleteFrontendPayloadValidates(): void
    {
        $schemaPath = LitSchema::DIOCESAN->path();
        $schema     = Schema::import($schemaPath);

        $payload = self::loadFixture('valid_diocesan_calendar.json');

        // Verify all expected properties are present
        $this->assertObjectHasProperty('litcal', $payload);
        $this->assertIsArray($payload->litcal);
        $this->assertObjectHasProperty('metadata', $payload);
        $this->assertObjectHasProperty('diocese_id', $payload->metadata);
        $this->assertObjectHasProperty('diocese_name', $payload->metadata);
        $this->assertObjectHasProperty('nation', $payload->metadata);
        $this->assertObjectHasProperty('locales', $payload->metadata);
        $this->assertObjectHasProperty('timezone', $payload->metadata);

        // Verify litcal items have correct structure
        foreach ($payload->litcal as $item) {
            $this->assertObjectHasProperty('liturgical_event', $item);
            $this->assertObjectHasProperty('metadata', $item);
            $this->assertObjectHasProperty('event_key', $item->liturgical_event);
        }

        // Schema validation should pass
        $schema->in($payload);
        $this->assertTrue(true, 'Complete frontend payload should pass validation');
    }
}
