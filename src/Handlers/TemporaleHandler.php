<?php

namespace LiturgicalCalendar\Api\Handlers;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LectionaryCategory;
use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\ReadingsType;
use LiturgicalCalendar\Api\FerialEventNameGenerator;
use LiturgicalCalendar\Api\Handlers\Auth\ClientIpTrait;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\ConflictException;
use LiturgicalCalendar\Api\Http\Exception\InternalServerErrorException;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use LiturgicalCalendar\Api\Http\Negotiator;
use LiturgicalCalendar\Api\Utilities;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles the `/temporale` path of the API.
 *
 * This is the path that handles the Proprium de Tempore (Temporale) data.
 * The source data can be retrieved (GET), created (PUT), updated (PATCH), or deleted (DELETE).
 *
 * GET/POST: Returns the temporale events with translated names based on Accept-Language header.
 * PUT: Replace the entire temporale data (authenticated).
 * PATCH: Update specific temporale events (authenticated).
 * DELETE: Remove a specific temporale event by event_key (authenticated).
 */
final class TemporaleHandler extends AbstractHandler
{
    use ClientIpTrait;

    private string $locale;
    private Logger $auditLogger;
    private Logger $debugLogger;
    private string $clientIp = 'unknown';

    /** @var string[] Available locale files in the temporale i18n folder */
    private array $availableLocales = [];

    /** @var string[] Available locale files in the lectionary folder */
    private array $availableLectionaryLocales = [];

    /** @param string[] $requestPathParams */
    public function __construct(array $requestPathParams = [])
    {
        parent::__construct($requestPathParams);
        // Allow credentials for cross-origin cookie requests (required for authenticated PUT/PATCH/DELETE)
        $this->allowCredentials = true;
        // Initialize the list of available locales
        LitLocale::init();
        // Initialize audit logger for write operations (security-relevant actions)
        $this->auditLogger = LoggerFactory::create('audit', null, 90, false, true, false);
        // Initialize debug logger for diagnostics (non-security warnings)
        $this->debugLogger = LoggerFactory::create('temporale_debug', null, 90, false, false, false);
        // Build available locales list from i18n folder
        $this->buildAvailableLocales();
        // Build available lectionary locales list
        $this->buildAvailableLectionaryLocales();
    }

    /**
     * Builds the list of available locales from the i18n folder.
     */
    private function buildAvailableLocales(): void
    {
        $i18nFolder = JsonData::TEMPORALE_I18N_FOLDER->path();
        if (is_dir($i18nFolder)) {
            $files = glob($i18nFolder . '/*.json');
            if ($files !== false) {
                foreach ($files as $file) {
                    if (!is_readable($file)) {
                        continue;
                    }
                    $locale                   = basename($file, '.json');
                    $this->availableLocales[] = $locale;
                }
            }
        }
    }

    /**
     * Builds the list of available locales from the lectionary folder.
     *
     * ARCHITECTURAL CONSTRAINT: Uses Year A folder as the authoritative source.
     * All three year-cycle folders (A, B, C) MUST have identical locale coverage.
     * This is enforced by the translation workflow - when a new locale is added,
     * it must be added to all three year folders simultaneously.
     *
     * The loadLectionaryData() method is defensive and skips missing files,
     * so if a locale exists in Year A but not in B or C, those years will
     * simply have no readings for that locale (graceful degradation).
     */
    private function buildAvailableLectionaryLocales(): void
    {
        $lectionaryFolder = JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_A_FOLDER->path();
        if (is_dir($lectionaryFolder)) {
            $files = glob($lectionaryFolder . '/*.json');
            if ($files !== false) {
                foreach ($files as $file) {
                    if (!is_readable($file)) {
                        continue;
                    }
                    $locale                             = basename($file, '.json');
                    $this->availableLectionaryLocales[] = $locale;

                    // Diagnostic: verify locale exists in Year B and C folders
                    $yearBFile = strtr(JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_B_FILE->path(), ['{locale}' => $locale]);
                    $yearCFile = strtr(JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_C_FILE->path(), ['{locale}' => $locale]);
                    if (!file_exists($yearBFile)) {
                        $this->debugLogger->warning("Lectionary locale '{$locale}' exists in Year A but missing in Year B");
                    }
                    if (!file_exists($yearCFile)) {
                        $this->debugLogger->warning("Lectionary locale '{$locale}' exists in Year A but missing in Year C");
                    }
                }
            }
        }
    }

    /**
     * Extract base locale from a locale string (e.g., 'en' from 'en_US' or 'en-US').
     *
     * @param string $locale The locale string to parse
     * @return string The base locale (first segment before _ or -)
     */
    private function getBaseLocale(string $locale): string
    {
        $localeParts = preg_split('/[_-]/', $locale);
        return ( is_array($localeParts) && count($localeParts) > 0 ) ? $localeParts[0] : $locale;
    }

    /**
     * Select and validate a locale for temporale data.
     *
     * Checks if the locale (or its base form) is available in the temporale translations.
     * For Accept-Language derived locales, falls back to Latin if not available.
     * For explicit query parameter locales, throws an exception if not available.
     *
     * @param string $locale The locale to select
     * @param bool $throwOnUnavailable Whether to throw an exception if locale is unavailable
     * @return string The selected locale
     * @throws ValidationException If the locale is invalid or unavailable (when $throwOnUnavailable is true)
     */
    private function selectLocale(string $locale, bool $throwOnUnavailable = false): string
    {
        $canonicalized = \Locale::canonicalize($locale);
        if (null === $canonicalized || '' === $canonicalized) {
            if ($throwOnUnavailable) {
                throw new ValidationException("Invalid locale value: '{$locale}'");
            }
            return LitLocale::LATIN_PRIMARY_LANGUAGE;
        }

        if (!LitLocale::isValid($canonicalized)) {
            if ($throwOnUnavailable) {
                throw new ValidationException(
                    "Invalid value '{$locale}' for param `locale`, valid values are: la, la_VA, "
                    . implode(', ', LitLocale::$AllAvailableLocales)
                );
            }
            return LitLocale::LATIN_PRIMARY_LANGUAGE;
        }

        $baseLocale = $this->getBaseLocale($canonicalized);
        if (in_array($canonicalized, $this->availableLocales, true)) {
            return $canonicalized;
        } elseif (in_array($baseLocale, $this->availableLocales, true)) {
            return $baseLocale;
        }

        if ($throwOnUnavailable) {
            throw new ValidationException(
                "Locale '{$locale}' is not available for temporale data. "
                . 'Available locales: ' . implode(', ', $this->availableLocales)
            );
        }

        return LitLocale::LATIN_PRIMARY_LANGUAGE;
    }

    /**
     * Determines the lectionary locale to use based on the current locale.
     * Falls back to base locale, then Latin if the exact locale is not available.
     *
     * @return string|null The lectionary locale to use, or null if none available
     */
    private function getLectionaryLocale(): ?string
    {
        // First try exact match
        if (in_array($this->locale, $this->availableLectionaryLocales, true)) {
            return $this->locale;
        }

        // Try base locale (e.g., 'en' from 'en_US')
        $baseLocale = $this->getBaseLocale($this->locale);
        if (in_array($baseLocale, $this->availableLectionaryLocales, true)) {
            return $baseLocale;
        }

        // Fall back to Latin
        if (in_array('la', $this->availableLectionaryLocales, true)) {
            return 'la';
        }

        return null;
    }

    /**
     * Handles the request for the /temporale endpoint.
     *
     * If the request method is GET or POST, it will return the temporale events with translated names.
     * If the request method is PUT, it will replace the entire temporale data.
     * If the request method is PATCH, it will update specific temporale events.
     * If the request method is DELETE, it will remove a specific temporale event by event_key.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // We instantiate a Response object with minimum state
        $response = static::initResponse($request);

        $method = RequestMethod::from($request->getMethod());

        // OPTIONS method for CORS preflight requests is always allowed
        if ($method === RequestMethod::OPTIONS) {
            return $this->handlePreflightRequest($request, $response);
        } else {
            $response = $this->setAccessControlAllowOriginHeader($request, $response);
        }

        // First of all we validate that the Content-Type requested in the Accept header is supported by the endpoint
        switch ($method) {
            case RequestMethod::GET:
                $mime = $this->validateAcceptHeader($request, AcceptabilityLevel::LAX);
                break;
            default:
                $mime = $this->validateAcceptHeader($request, AcceptabilityLevel::INTERMEDIATE);
        }

        $response = $response->withHeader('Content-Type', $mime);

        // Check Accept-Language header for locale (fallback to Latin if unavailable)
        $locale       = Negotiator::pickLanguage($request, [], LitLocale::LATIN);
        $this->locale = $locale ? $this->selectLocale($locale, false) : LitLocale::LATIN_PRIMARY_LANGUAGE;

        // Check for locale query parameter (overrides Accept-Language header, throws on invalid)
        $queryParams = $request->getQueryParams();
        if (array_key_exists('locale', $queryParams) && is_string($queryParams['locale'])) {
            $this->locale = $this->selectLocale($queryParams['locale'], true);
        }

        // Capture client IP for audit logging
        /** @var array<string,mixed> $serverParams */
        $serverParams   = $request->getServerParams();
        $this->clientIp = $this->getClientIp($request, $serverParams);

        // Validate request method
        $this->validateRequestMethod($request);

        switch ($method) {
            case RequestMethod::GET:
                // no break (intentional fallthrough)
            case RequestMethod::POST:
                return $this->handleGetRequest($response);
            case RequestMethod::PUT:
                return $this->handlePutRequest($request, $response);
            case RequestMethod::PATCH:
                return $this->handlePatchRequest($request, $response);
            case RequestMethod::DELETE:
                return $this->handleDeleteRequest($response);
            default:
                throw new MethodNotAllowedException();
        }
    }

    /**
     * Handle GET and POST requests to retrieve temporale data.
     *
     * Returns the temporale events with translated names and lectionary readings based on the locale.
     * Includes synthetic events for ferial days from lectionary folders with grade=0 (WEEKDAY).
     */
    private function handleGetRequest(ResponseInterface $response): ResponseInterface
    {
        $temporaleFile = JsonData::TEMPORALE_FILE->path();
        if (!file_exists($temporaleFile)) {
            throw new NotFoundException('Temporale data file not found');
        }

        $temporaleRows = Utilities::jsonFileToObjectArray($temporaleFile);

        // Build a set of existing event keys
        $existingKeys = [];
        foreach ($temporaleRows as $row) {
            if (property_exists($row, 'event_key') && is_string($row->event_key)) {
                $existingKeys[$row->event_key] = true;
            }
        }

        // Load translations
        $i18nFile = strtr(JsonData::TEMPORALE_I18N_FILE->path(), ['{locale}' => $this->locale]);
        $i18nObj  = null;
        if (file_exists($i18nFile)) {
            $i18nObj = Utilities::jsonFileToObject($i18nFile);

            /** @var array<int,\stdClass&object{event_key:string,grade:int,type:string,color:string[]}> $temporaleRows */
            foreach ($temporaleRows as $idx => $row) {
                $key = $row->event_key;
                if (property_exists($i18nObj, $key)) {
                    $temporaleRows[$idx]->name = $i18nObj->{$key};
                }
            }
        }

        // Load lectionary readings for all three year cycles
        $lectionaryLocale = $this->getLectionaryLocale();
        if ($lectionaryLocale !== null) {
            $lectionaryData = $this->loadLectionaryData($lectionaryLocale);
            $sanctorumData  = $this->loadSanctorumLectionaryData($lectionaryLocale);
            $ferialData     = $this->loadFerialLectionaryData($lectionaryLocale);

            /** @var array<int,\stdClass&object{event_key:string,grade:int,type:string,color:string[]}> $temporaleRows */
            foreach ($temporaleRows as $idx => $row) {
                $key = $row->event_key;

                // Special case: ImmaculateHeart readings are in sanctorum, not year-cycle based
                if ($key === 'ImmaculateHeart' && $sanctorumData !== null && property_exists($sanctorumData, $key)) {
                    $temporaleRows[$idx]->readings = $sanctorumData->{$key};
                    continue;
                }

                // Check ferial lectionaries (weekdays) - flat structure, no year cycles
                if (property_exists($ferialData, $key)) {
                    $temporaleRows[$idx]->readings = $ferialData->{$key};
                    continue;
                }

                // Build readings object with year cycles (for Sundays/Solemnities)
                $readings    = new \stdClass();
                $hasReadings = false;

                // Add Year A readings
                if (isset($lectionaryData['A']) && property_exists($lectionaryData['A'], $key)) {
                    $readings->annum_a = $lectionaryData['A']->{$key};
                    $hasReadings       = true;
                }

                // Add Year B readings
                if (isset($lectionaryData['B']) && property_exists($lectionaryData['B'], $key)) {
                    $readings->annum_b = $lectionaryData['B']->{$key};
                    $hasReadings       = true;
                }

                // Add Year C readings
                if (isset($lectionaryData['C']) && property_exists($lectionaryData['C'], $key)) {
                    $readings->annum_c = $lectionaryData['C']->{$key};
                    $hasReadings       = true;
                }

                // Only add readings property if at least one year cycle has data
                if ($hasReadings) {
                    $temporaleRows[$idx]->readings = $readings;
                }
            }

            // Generate synthetic events for ferial days not in the main temporale file
            $ferialEvents  = $this->generateFerialEvents($ferialData, $existingKeys, $i18nObj);
            $temporaleRows = array_merge($temporaleRows, $ferialEvents);
        }

        $response = $response->withHeader('X-Litcal-Temporale-Locale', $this->locale);
        return $this->encodeResponseBody($response, [
            'events' => $temporaleRows,
            'locale' => $this->locale
        ]);
    }

    /**
     * Generate synthetic events for ferial days from lectionary data.
     *
     * Creates event objects for weekdays that are not in the main temporale file
     * but have lectionary readings.
     *
     * @param \stdClass $ferialData The ferial lectionary data
     * @param array<string,bool> $existingKeys Keys of events already in main temporale
     * @param \stdClass|null $i18nObj Translations object
     * @return \stdClass[] Array of synthetic event objects
     */
    private function generateFerialEvents(\stdClass $ferialData, array $existingKeys, ?\stdClass $i18nObj): array
    {
        $events        = [];
        $nameGenerator = new FerialEventNameGenerator($this->locale);

        foreach (get_object_vars($ferialData) as $eventKey => $readings) {
            // Skip if this event already exists in the main temporale file
            if (isset($existingKeys[$eventKey])) {
                continue;
            }

            $category = LectionaryCategory::forEventKey($eventKey);

            // Only generate events for ferial categories
            if (!$category->isFerial()) {
                continue;
            }

            $event            = new \stdClass();
            $event->event_key = $eventKey;
            $event->grade     = 0; // WEEKDAY
            $event->type      = 'mobile';
            $event->color     = $category->liturgicalColor();
            $event->readings  = $readings;

            // Generate name using gettext (ferial events don't have i18n file entries)
            $generatedName = $nameGenerator->generateName($eventKey);
            if ($generatedName !== null) {
                $event->name = $generatedName;
            } elseif ($i18nObj !== null && property_exists($i18nObj, $eventKey)) {
                // Fallback to i18n if available (shouldn't happen for ferial events)
                $event->name = $i18nObj->{$eventKey};
            }

            $events[] = $event;
        }

        return $events;
    }

    /**
     * Load lectionary data for all three year cycles.
     *
     * @param string $locale The locale for the lectionary files
     * @return array<string,\stdClass> Array with keys 'A', 'B', 'C' containing lectionary data
     */
    private function loadLectionaryData(string $locale): array
    {
        $lectionaryData = [];
        $yearMappings   = [
            'A' => JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_A_FILE,
            'B' => JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_B_FILE,
            'C' => JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_C_FILE,
        ];

        foreach ($yearMappings as $year => $jsonDataEnum) {
            $file = strtr($jsonDataEnum->path(), ['{locale}' => $locale]);
            if (file_exists($file)) {
                try {
                    $lectionaryData[$year] = Utilities::jsonFileToObject($file);
                } catch (\JsonException | ServiceUnavailableException $e) {
                    // Skip if file cannot be parsed or is unavailable
                    $this->auditLogger->debug("Failed to load Year {$year} lectionary for locale '{$locale}'", [
                        'file'  => $file,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return $lectionaryData;
    }

    /**
     * Load sanctorum (saints) lectionary data.
     *
     * This is used for events like ImmaculateHeart which are in the temporale
     * but have their lectionary readings in the sanctorum folder.
     *
     * @param string $locale The locale for the lectionary file
     * @return \stdClass|null The sanctorum lectionary data, or null if unavailable
     */
    private function loadSanctorumLectionaryData(string $locale): ?\stdClass
    {
        $file = strtr(JsonData::LECTIONARY_SAINTS_FILE->path(), ['{locale}' => $locale]);
        if (file_exists($file)) {
            try {
                return Utilities::jsonFileToObject($file);
            } catch (\JsonException | ServiceUnavailableException $e) {
                // Skip if file cannot be parsed or is unavailable
                $this->auditLogger->debug("Failed to load sanctorum lectionary for locale '{$locale}'", [
                    'file'  => $file,
                    'error' => $e->getMessage()
                ]);
            }
        }
        return null;
    }

    /**
     * Load ferial (weekday) lectionary data from Lent and Easter seasons.
     *
     * These contain readings for weekday events like Ash Wednesday, Holy Week days,
     * and Easter Octave days that are not in the Sunday/Solemnity lectionary.
     *
     * @param string $locale The locale for the lectionary files
     * @return \stdClass Combined ferial lectionary data from all seasons
     */
    private function loadFerialLectionaryData(string $locale): \stdClass
    {
        $ferialData = new \stdClass();

        // Load all ferial lectionary categories (flat structure - no year cycle)
        $ferialMappings = [
            'Advent'    => JsonData::LECTIONARY_WEEKDAYS_ADVENT_FILE,
            'Christmas' => JsonData::LECTIONARY_WEEKDAYS_CHRISTMAS_FILE,
            'Lent'      => JsonData::LECTIONARY_WEEKDAYS_LENT_FILE,
            'Easter'    => JsonData::LECTIONARY_WEEKDAYS_EASTER_FILE,
        ];

        foreach ($ferialMappings as $season => $jsonDataEnum) {
            $file = strtr($jsonDataEnum->path(), ['{locale}' => $locale]);
            if (file_exists($file)) {
                try {
                    $seasonData = Utilities::jsonFileToObject($file);
                    foreach (get_object_vars($seasonData) as $key => $value) {
                        $ferialData->{$key} = $value;
                    }
                } catch (\JsonException | ServiceUnavailableException $e) {
                    // Skip if file cannot be parsed or is unavailable
                    $this->debugLogger->debug("Failed to load {$season} weekdays lectionary for locale '{$locale}'", [
                        'file'  => $file,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        // Load Ordinary Time weekdays (two-year cycle: Year I and Year II)
        $ordinaryYearI  = null;
        $ordinaryYearII = null;

        $yearIFile = strtr(JsonData::LECTIONARY_WEEKDAYS_ORDINARY_I_FILE->path(), ['{locale}' => $locale]);
        if (file_exists($yearIFile)) {
            try {
                $ordinaryYearI = Utilities::jsonFileToObject($yearIFile);
            } catch (\JsonException | ServiceUnavailableException $e) {
                $this->debugLogger->debug("Failed to load Ordinary Time Year I lectionary for locale '{$locale}'", [
                    'file'  => $yearIFile,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $yearIIFile = strtr(JsonData::LECTIONARY_WEEKDAYS_ORDINARY_II_FILE->path(), ['{locale}' => $locale]);
        if (file_exists($yearIIFile)) {
            try {
                $ordinaryYearII = Utilities::jsonFileToObject($yearIIFile);
            } catch (\JsonException | ServiceUnavailableException $e) {
                $this->debugLogger->debug("Failed to load Ordinary Time Year II lectionary for locale '{$locale}'", [
                    'file'  => $yearIIFile,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Merge Ordinary Time readings with two-year cycle structure (annum_I, annum_II)
        if ($ordinaryYearI !== null || $ordinaryYearII !== null) {
            // Get all event keys from both years
            $yearIKeys  = $ordinaryYearI !== null ? array_keys(get_object_vars($ordinaryYearI)) : [];
            $yearIIKeys = $ordinaryYearII !== null ? array_keys(get_object_vars($ordinaryYearII)) : [];
            $allKeys    = array_unique(array_merge($yearIKeys, $yearIIKeys));

            foreach ($allKeys as $eventKey) {
                $readings           = new \stdClass();
                $readings->annum_I  = $ordinaryYearI !== null && property_exists($ordinaryYearI, $eventKey)
                    ? $ordinaryYearI->{$eventKey}
                    : null;
                $readings->annum_II = $ordinaryYearII !== null && property_exists($ordinaryYearII, $eventKey)
                    ? $ordinaryYearII->{$eventKey}
                    : null;

                $ferialData->{$eventKey} = $readings;
            }
        }

        return $ferialData;
    }

    /**
     * Handle PUT requests to create the entire temporale data.
     *
     * PUT is only allowed when NO temporale data exists (initial creation only).
     * Requires JWT authentication (handled by middleware).
     *
     * Expected payload structure (unified per-event structure):
     * {
     *   "locales": ["en", "la", "de", ...],
     *   "events": [
     *     {
     *       "event_key": "Easter",
     *       "grade": 7,
     *       "type": "mobile",
     *       "color": ["white"],
     *       "i18n": {
     *         "en": "Easter Sunday",
     *         "la": "Dominica Paschatis"
     *       },
     *       "readings": {
     *         "en": { "annum_a": {...}, "annum_b": {...}, "annum_c": {...} }
     *       }
     *     }
     *   ]
     * }
     */
    private function handlePutRequest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Check if temporale data already exists - PUT only allowed for initial creation
        $temporaleFile = JsonData::TEMPORALE_FILE->path();
        if (file_exists($temporaleFile)) {
            try {
                $existingData = Utilities::jsonFileToObjectArray($temporaleFile);
                if (count($existingData) > 0) {
                    throw new ConflictException('Temporale data already exists. Use PATCH to update existing data.');
                }
            } catch (\JsonException $e) {
                // Empty or malformed file - treat as no existing data, continue with PUT
            } catch (ServiceUnavailableException $e) {
                // Propagate service errors (e.g., file read failure)
                throw $e;
            }
        }

        $payload = $this->parseBodyPayload($request, false);

        if (!( $payload instanceof \stdClass )) {
            throw new ValidationException('Request body must be an object with locales and events properties');
        }

        // Validate required properties
        if (!property_exists($payload, 'locales') || !is_array($payload->locales)) {
            throw new ValidationException('Payload must have a "locales" array property');
        }
        if (count($payload->locales) === 0) {
            throw new ValidationException('Locales array must contain at least one locale');
        }
        if (!property_exists($payload, 'events') || !is_array($payload->events)) {
            throw new ValidationException('Payload must have an "events" array property');
        }
        if (count($payload->events) === 0) {
            throw new ValidationException('Events array must contain at least one event');
        }

        /** @var array<int,string> $locales */
        $locales = $payload->locales;

        /** @var array<int,mixed> $events */
        $events = $payload->events;

        // Validate locales are base locale strings
        foreach ($locales as $locale) {
            if (!is_string($locale) || empty($locale)) {
                throw new ValidationException('Each locale must be a non-empty string');
            }
            if (str_contains($locale, '_') || str_contains($locale, '-')) {
                throw new ValidationException("Locale '{$locale}' must be a base locale without regional identifiers");
            }
        }

        // Determine Accept-Language locale (base locale only)
        $acceptLocale = $this->getBaseLocale($this->locale);

        // Validate each event and collect event_keys
        /** @var array<string,bool> $seenEventKeys */
        $seenEventKeys = [];
        /** @var string[] $eventKeys Event keys for non-grade-0 events (stored in main file) */
        $eventKeys = [];

        /** @var array<int,\stdClass> $validatedEvents Events to store in main temporale file (non-grade-0) */
        $validatedEvents = [];

        /** @var array<int,\stdClass> $ferialEvents Grade 0 events (only readings go to lectionary files) */
        $ferialEvents = [];

        foreach ($events as $event) {
            if (!( $event instanceof \stdClass )) {
                throw new ValidationException('Each event must be an object');
            }
            $this->validateTemporaleEvent($event);

            /** @var string $eventKey */
            $eventKey = $event->event_key;
            if (isset($seenEventKeys[$eventKey])) {
                throw new ValidationException("Duplicate event_key '{$eventKey}' in payload");
            }
            $seenEventKeys[$eventKey] = true;

            // Grade 0 events (ferial/weekday) are handled differently:
            // - NOT stored in main temporale file (dynamically generated from lectionary)
            // - NO i18n data (translations come from gettext in CalendarHandler)
            // - Readings ARE stored in lectionary files
            $isGradeZero = $event->grade === 0;

            if ($isGradeZero) {
                // Grade 0: i18n should NOT be provided (translations from gettext)
                if (property_exists($event, 'i18n') && $event->i18n instanceof \stdClass) {
                    $i18nKeys = array_keys(get_object_vars($event->i18n));
                    if (count($i18nKeys) > 0) {
                        throw new ValidationException(
                            "Grade 0 event '{$eventKey}' should not have i18n data (translations are from gettext)"
                        );
                    }
                }

                // Grade 0: readings are required
                if (!property_exists($event, 'readings') || !( $event->readings instanceof \stdClass )) {
                    throw new ValidationException("Grade 0 event '{$eventKey}' must have a 'readings' object property");
                }
                $this->validateEventReadings($event->readings, $eventKey);

                $ferialEvents[] = $event;
            } else {
                // Non-grade-0: i18n is required
                if (!property_exists($event, 'i18n') || !( $event->i18n instanceof \stdClass )) {
                    throw new ValidationException("Event '{$eventKey}' must have an 'i18n' object property");
                }
                $this->validateEventI18n($event->i18n, $eventKey);

                // Ensure i18n has the Accept-Language locale
                if (!property_exists($event->i18n, $acceptLocale)) {
                    throw new ValidationException(
                        "Event '{$eventKey}' i18n must contain translation for Accept-Language locale '{$acceptLocale}'"
                    );
                }

                // Non-grade-0: readings are required
                if (!property_exists($event, 'readings') || !( $event->readings instanceof \stdClass )) {
                    throw new ValidationException("Event '{$eventKey}' must have a 'readings' object property");
                }
                $this->validateEventReadings($event->readings, $eventKey);

                $eventKeys[]       = $eventKey;
                $validatedEvents[] = $event;
            }
        }

        // Extract i18n from non-grade-0 events and build data for all locales
        $extractedI18n = $this->extractI18nFromEvents($validatedEvents);

        // Build i18n data for all locales (with empty placeholders for missing translations)
        // Only for non-grade-0 events
        $i18nToWrite = new \stdClass();
        foreach ($locales as $locale) {
            $i18nToWrite->{$locale} = new \stdClass();
            /** @var \stdClass|null $localeTranslations */
            $localeTranslations = property_exists($extractedI18n, $locale) ? $extractedI18n->{$locale} : null;
            foreach ($eventKeys as $eventKey) {
                if ($localeTranslations instanceof \stdClass && property_exists($localeTranslations, $eventKey)) {
                    $i18nToWrite->{$locale}->{$eventKey} = $localeTranslations->{$eventKey};
                } else {
                    // Empty string placeholder for missing translations
                    $i18nToWrite->{$locale}->{$eventKey} = '';
                }
            }
        }

        // Write i18n files for each locale (only non-grade-0 events)
        if (count($eventKeys) > 0) {
            $this->writeI18nFiles($i18nToWrite);
        }

        // Extract readings from ALL events (both non-grade-0 and grade-0) and write to lectionary files
        $allEventsWithReadings = array_merge($validatedEvents, $ferialEvents);
        $extractedReadings     = $this->extractReadingsFromEvents($allEventsWithReadings);
        $readingsLocales       = array_keys(get_object_vars($extractedReadings));
        if (count($readingsLocales) > 0) {
            $this->writeLectionaryFiles($extractedReadings);
        }

        // Strip i18n and readings before saving to main file (only non-grade-0 events)
        $eventsToStore = $this->stripI18nAndReadingsFromEvents($validatedEvents);

        // Write events to main temporale file (only non-grade-0 events)
        $jsonContent = json_encode($eventsToStore, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($jsonContent === false) {
            throw new ValidationException('Failed to encode temporale data as JSON');
        }

        $result = file_put_contents($temporaleFile, $jsonContent, LOCK_EX);
        if ($result === false) {
            throw new InternalServerErrorException('Failed to write temporale data to file');
        }

        // Invalidate APCu cache for the temporale file
        Utilities::invalidateJsonFileCache($temporaleFile);

        // Update available locales list
        $this->availableLocales = array_values(array_unique(array_merge($this->availableLocales, $locales)));

        // Log the operation
        $this->auditLogger->info('Temporale data created', [
            'operation'        => 'PUT',
            'client_ip'        => $this->clientIp,
            'file'             => $temporaleFile,
            'events'           => count($validatedEvents),
            'ferial_events'    => count($ferialEvents),
            'i18n_locales'     => $locales,
            'readings_locales' => $readingsLocales
        ]);

        return $this->encodeResponseBody($response, [
            'success'       => true,
            'message'       => 'Temporale data created successfully',
            'events'        => count($validatedEvents),
            'ferial_events' => count($ferialEvents)
        ], StatusCode::CREATED);
    }

    /**
     * Handle PATCH requests to update specific temporale events.
     *
     * Requires JWT authentication (handled by middleware).
     *
     * Expected payload structure (unified per-event structure):
     * {
     *   "events": [
     *     {
     *       "event_key": "Easter",
     *       "grade": 7,
     *       "type": "mobile",
     *       "color": ["white"],
     *       "i18n": {              // required for new events, optional for updates
     *         "en": "Easter Sunday"
     *       },
     *       "readings": {          // required for new events, optional for updates
     *         "en": { "annum_a": {...}, "annum_b": {...}, "annum_c": {...} }
     *       }
     *     }
     *   ]
     * }
     */
    private function handlePatchRequest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = $this->parseBodyPayload($request, false);

        if (!( $payload instanceof \stdClass )) {
            throw new ValidationException('Request body must be an object with events property');
        }

        // Validate required properties
        if (!property_exists($payload, 'events') || !is_array($payload->events)) {
            throw new ValidationException('Payload must have an "events" array property');
        }

        /** @var array<int,mixed> $events */
        $events = $payload->events;

        // Load existing data
        $temporaleFile = JsonData::TEMPORALE_FILE->path();
        if (!file_exists($temporaleFile)) {
            throw new NotFoundException('Temporale data file not found');
        }

        $existingData = Utilities::jsonFileToObjectArray($temporaleFile);

        // Build a map of existing events by event_key
        /** @var array<string,int> $existingEventKeyToIndex */
        $existingEventKeyToIndex = [];
        foreach ($existingData as $idx => $event) {
            if (property_exists($event, 'event_key') && is_string($event->event_key)) {
                $existingEventKeyToIndex[$event->event_key] = (int) $idx;
            }
        }

        // Check for duplicate event_keys in the payload
        /** @var array<string,bool> $seenEventKeys */
        $seenEventKeys = [];

        $updatedCount = 0;
        $addedCount   = 0;
        /** @var string[] $newEventKeys Event keys for new non-grade-0 events */
        $newEventKeys = [];

        // Determine Accept-Language locale (base locale only)
        $acceptLocale = $this->getBaseLocale($this->locale);

        /** @var array<int,\stdClass> $validatedEvents Events to store in main temporale file (non-grade-0) */
        $validatedEvents = [];

        /** @var array<int,\stdClass> $ferialEvents Grade 0 events (only readings go to lectionary files) */
        $ferialEvents = [];
        $ferialCount  = 0;

        // Process each event in the payload
        foreach ($events as $event) {
            if (!( $event instanceof \stdClass )) {
                throw new ValidationException('Each event must be an object');
            }
            if (!property_exists($event, 'event_key') || !is_string($event->event_key)) {
                throw new ValidationException('Each event must have an event_key property');
            }

            /** @var string $eventKey */
            $eventKey = $event->event_key;

            if (isset($seenEventKeys[$eventKey])) {
                throw new ValidationException("Duplicate event_key '{$eventKey}' in payload");
            }
            $seenEventKeys[$eventKey] = true;

            $this->validateTemporaleEvent($event);

            // Grade 0 events (ferial/weekday) are handled differently
            $isGradeZero = $event->grade === 0;

            if ($isGradeZero) {
                // Grade 0: i18n should NOT be provided (translations from gettext)
                if (property_exists($event, 'i18n') && $event->i18n instanceof \stdClass) {
                    $i18nKeys = array_keys(get_object_vars($event->i18n));
                    if (count($i18nKeys) > 0) {
                        throw new ValidationException(
                            "Grade 0 event '{$eventKey}' should not have i18n data (translations are from gettext)"
                        );
                    }
                }

                // Grade 0: readings are required
                if (!property_exists($event, 'readings') || !( $event->readings instanceof \stdClass )) {
                    throw new ValidationException("Grade 0 event '{$eventKey}' must have a 'readings' object property");
                }
                $this->validateEventReadings($event->readings, $eventKey);

                $ferialEvents[] = $event;
                $ferialCount++;
            } else {
                // Non-grade-0 event
                $isNewEvent = !isset($existingEventKeyToIndex[$eventKey]);

                // Validate per-event i18n
                if (property_exists($event, 'i18n') && $event->i18n instanceof \stdClass) {
                    $this->validateEventI18n($event->i18n, $eventKey);
                }

                // For new events, require i18n with Accept-Language locale
                if ($isNewEvent) {
                    if (!property_exists($event, 'i18n') || !( $event->i18n instanceof \stdClass )) {
                        throw new ValidationException(
                            "New event '{$eventKey}' must have an 'i18n' object property"
                        );
                    }
                    if (!property_exists($event->i18n, $acceptLocale)) {
                        throw new ValidationException(
                            "New event '{$eventKey}' i18n must contain translation for Accept-Language locale '{$acceptLocale}'"
                        );
                    }
                    $newEventKeys[] = $eventKey;
                    $addedCount++;
                } else {
                    $updatedCount++;
                }

                // Validate per-event readings
                if (property_exists($event, 'readings') && $event->readings instanceof \stdClass) {
                    $this->validateEventReadings($event->readings, $eventKey);
                } elseif ($isNewEvent) {
                    // For new events, readings are required
                    throw new ValidationException(
                        "New event '{$eventKey}' must have a 'readings' object property"
                    );
                }

                $validatedEvents[] = $event;
            }
        }

        // Extract i18n from non-grade-0 events and update files
        $extractedI18n = $this->extractI18nFromEvents($validatedEvents);
        $i18nLocales   = array_keys(get_object_vars($extractedI18n));

        // Track which locales are newly created (not in previous availableLocales)
        $previousLocales     = $this->availableLocales;
        $newlyCreatedLocales = [];

        if (count($i18nLocales) > 0) {
            $this->updateI18nFiles($extractedI18n);

            // Merge newly created locales into availableLocales so ensureI18nConsistency can process them
            $this->availableLocales = array_values(array_unique(array_merge($previousLocales, $i18nLocales)));
            $newlyCreatedLocales    = array_diff($i18nLocales, $previousLocales);
        }

        // Ensure all new event_keys have entries in all i18n files (empty string placeholder)
        // Only for non-grade-0 events
        if (count($newEventKeys) > 0) {
            $this->ensureI18nConsistency($newEventKeys, $acceptLocale);
        }

        // For newly created locales, ensure they have placeholders for ALL existing event keys
        // This ensures new locale files contain all events, not just the new ones from this PATCH
        if (count($newlyCreatedLocales) > 0) {
            // Use the existing map keys which are already validated as strings
            $allExistingEventKeys = array_keys($existingEventKeyToIndex);
            $this->ensureI18nConsistency($allExistingEventKeys, null, $newlyCreatedLocales);
        }

        // Extract readings from ALL events (both non-grade-0 and grade-0) and update lectionary files
        $allEventsWithReadings = array_merge($validatedEvents, $ferialEvents);
        $extractedReadings     = $this->extractReadingsFromEvents($allEventsWithReadings);
        $readingsLocales       = array_keys(get_object_vars($extractedReadings));
        if (count($readingsLocales) > 0) {
            $this->updateLectionaryFiles($extractedReadings);
        }

        // Strip i18n and readings before storing events (only non-grade-0)
        $eventsToStore = $this->stripI18nAndReadingsFromEvents($validatedEvents);

        // Update existing data with new/updated events (only non-grade-0)
        foreach ($eventsToStore as $event) {
            /** @var string $eventKey */
            $eventKey = $event->event_key;
            if (isset($existingEventKeyToIndex[$eventKey])) {
                // Update existing event
                $existingData[$existingEventKeyToIndex[$eventKey]] = $event;
            } else {
                // Add new event
                $existingData[] = $event;
            }
        }

        $jsonContent = json_encode(array_values($existingData), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($jsonContent === false) {
            throw new ValidationException('Failed to encode temporale data as JSON');
        }

        $result = file_put_contents($temporaleFile, $jsonContent, LOCK_EX);
        if ($result === false) {
            throw new InternalServerErrorException('Failed to write temporale data to file');
        }

        // Invalidate APCu cache for the temporale file
        Utilities::invalidateJsonFileCache($temporaleFile);

        // Log the operation
        $this->auditLogger->info('Temporale data updated', [
            'operation'        => 'PATCH',
            'client_ip'        => $this->clientIp,
            'file'             => $temporaleFile,
            'updated'          => $updatedCount,
            'added'            => $addedCount,
            'ferial_updated'   => $ferialCount,
            'i18n_locales'     => $i18nLocales,
            'readings_locales' => $readingsLocales
        ]);

        return $this->encodeResponseBody($response, [
            'success'        => true,
            'message'        => 'Temporale data updated successfully',
            'updated'        => $updatedCount,
            'added'          => $addedCount,
            'ferial_updated' => $ferialCount
        ], StatusCode::OK);
    }

    /**
     * Handle DELETE requests to remove a specific temporale event.
     *
     * Requires JWT authentication (handled by middleware).
     * The event_key is expected as a path parameter: DELETE /temporale/{event_key}
     *
     * For grade 0 (ferial/weekday) events that only exist in lectionary files,
     * this will delete the event from lectionary files only.
     */
    private function handleDeleteRequest(ResponseInterface $response): ResponseInterface
    {
        if (count($this->requestPathParams) !== 1) {
            throw new ValidationException('DELETE requires exactly one path parameter: the event_key');
        }

        $eventKey = $this->requestPathParams[0];

        // Load existing data
        $temporaleFile = JsonData::TEMPORALE_FILE->path();
        if (!file_exists($temporaleFile)) {
            throw new NotFoundException('Temporale data file not found');
        }

        $existingData = Utilities::jsonFileToObjectArray($temporaleFile);

        // Find the event index in a single pass.
        // Note: Using foreach instead of array_find() + array_search() to avoid traversing
        // the array twice - array_find returns the value but we need the index for array_splice.
        $foundIndex = null;
        foreach ($existingData as $idx => $event) {
            if (property_exists($event, 'event_key') && $event->event_key === $eventKey) {
                $foundIndex = $idx;
                break;
            }
        }

        // Check if this is a grade 0 (ferial) event that only exists in lectionary files
        $isFerialEvent = false;
        if ($foundIndex === null) {
            $category = LectionaryCategory::forEventKey($eventKey);
            if ($category->isFerial()) {
                $isFerialEvent = true;
            } else {
                throw new NotFoundException("Temporale event with key '{$eventKey}' not found");
            }
        }

        if ($isFerialEvent) {
            // Grade 0 events: only remove from lectionary files (no main file or i18n)
            $this->removeEventKeyFromLectionaryFiles($eventKey);

            // Log the operation
            $this->auditLogger->info('Ferial temporale event deleted from lectionary', [
                'operation' => 'DELETE',
                'client_ip' => $this->clientIp,
                'event_key' => $eventKey,
                'type'      => 'ferial'
            ]);

            return $this->encodeResponseBody($response, [
                'success'   => true,
                'message'   => "Ferial event '{$eventKey}' deleted from lectionary files",
                'event_key' => $eventKey,
                'type'      => 'ferial'
            ], StatusCode::OK);
        }

        // Non-ferial event: remove from main file, i18n, and lectionary

        // Remove the event from main temporale file
        array_splice($existingData, (int) $foundIndex, 1);

        $jsonContent = json_encode(array_values($existingData), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($jsonContent === false) {
            throw new ValidationException('Failed to encode temporale data as JSON');
        }

        $result = file_put_contents($temporaleFile, $jsonContent, LOCK_EX);
        if ($result === false) {
            throw new InternalServerErrorException('Failed to write temporale data to file');
        }

        // Invalidate APCu cache for the temporale file
        Utilities::invalidateJsonFileCache($temporaleFile);

        // Remove from all i18n files
        $this->removeEventKeyFromI18nFiles($eventKey);

        // Remove from all lectionary files
        $this->removeEventKeyFromLectionaryFiles($eventKey);

        // Log the operation
        $this->auditLogger->info('Temporale event deleted', [
            'operation' => 'DELETE',
            'client_ip' => $this->clientIp,
            'file'      => $temporaleFile,
            'event_key' => $eventKey
        ]);

        return $this->encodeResponseBody($response, [
            'success'   => true,
            'message'   => "Temporale event '{$eventKey}' deleted successfully",
            'event_key' => $eventKey
        ], StatusCode::OK);
    }

    /**
     * Remove an event_key from all i18n translation files.
     *
     * @param string $eventKey The event key to remove from translation files.
     */
    private function removeEventKeyFromI18nFiles(string $eventKey): void
    {
        $i18nFolder = JsonData::TEMPORALE_I18N_FOLDER->path();
        if (!is_dir($i18nFolder)) {
            return;
        }

        foreach ($this->availableLocales as $locale) {
            $i18nFile = strtr(JsonData::TEMPORALE_I18N_FILE->path(), ['{locale}' => $locale]);
            if (!file_exists($i18nFile) || !is_file($i18nFile)) {
                continue;
            }

            try {
                $i18nData = Utilities::jsonFileToObject($i18nFile);
            } catch (\JsonException | ServiceUnavailableException $e) {
                $this->auditLogger->warning("Failed to read i18n file for locale '{$locale}'", [
                    'event_key' => $eventKey,
                    'locale'    => $locale,
                    'file'      => $i18nFile,
                    'error'     => $e->getMessage()
                ]);
                continue;
            } catch (\Throwable $e) {
                $this->auditLogger->warning("Unexpected error reading i18n file for locale '{$locale}'", [
                    'event_key' => $eventKey,
                    'locale'    => $locale,
                    'file'      => $i18nFile,
                    'error'     => $e->getMessage()
                ]);
                continue;
            }

            if (!property_exists($i18nData, $eventKey)) {
                continue;
            }

            unset($i18nData->{$eventKey});

            $jsonContent = json_encode($i18nData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($jsonContent === false) {
                $this->auditLogger->warning("Failed to encode i18n data for locale '{$locale}'", [
                    'event_key' => $eventKey,
                    'locale'    => $locale
                ]);
                continue;
            }

            $result = file_put_contents($i18nFile, $jsonContent, LOCK_EX);
            if ($result === false) {
                $this->auditLogger->warning("Failed to write i18n file for locale '{$locale}'", [
                    'event_key' => $eventKey,
                    'locale'    => $locale,
                    'file'      => $i18nFile
                ]);
            } else {
                // Invalidate APCu cache for this file
                Utilities::invalidateJsonFileCache($i18nFile);
            }
        }
    }

    /**
     * Ensure the i18n folder exists, creating it if necessary.
     *
     * @throws InternalServerErrorException If unable to create the directory.
     */
    private function ensureI18nFolderExists(): void
    {
        $i18nFolder = JsonData::TEMPORALE_I18N_FOLDER->path();
        if (!is_dir($i18nFolder)) {
            if (!@mkdir($i18nFolder, 0755, true) && !is_dir($i18nFolder)) {
                throw new InternalServerErrorException('Failed to create i18n directory: ' . $i18nFolder);
            }
        }
    }

    /**
     * Write i18n data to locale files (for PUT - full replacement).
     *
     * @param \stdClass $i18n Object with locale keys and translation maps.
     * @throws InternalServerErrorException If unable to write to a file.
     */
    private function writeI18nFiles(\stdClass $i18n): void
    {
        $this->ensureI18nFolderExists();

        /** @var array<string,\stdClass> $i18nArray */
        $i18nArray = get_object_vars($i18n);
        foreach ($i18nArray as $locale => $translations) {
            $i18nFile    = strtr(JsonData::TEMPORALE_I18N_FILE->path(), ['{locale}' => $locale]);
            $jsonContent = json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($jsonContent === false) {
                throw new InternalServerErrorException("Failed to encode i18n data for locale '{$locale}'");
            }

            $result = file_put_contents($i18nFile, $jsonContent, LOCK_EX);
            if ($result === false) {
                throw new InternalServerErrorException("Failed to write i18n file for locale '{$locale}'");
            }

            Utilities::invalidateJsonFileCache($i18nFile);
        }
    }

    /**
     * Validate i18n structure on an event.
     *
     * Expected structure:
     * {
     *   "i18n": {
     *     "en": "Easter Sunday",
     *     "la": "Dominica Paschatis"
     *   }
     * }
     *
     * @param \stdClass $i18n The i18n object to validate.
     * @param string $eventKey The event key (for error messages).
     * @throws ValidationException If the structure is invalid.
     */
    private function validateEventI18n(\stdClass $i18n, string $eventKey): void
    {
        /** @var array<string,mixed> $i18nArray */
        $i18nArray = get_object_vars($i18n);
        foreach ($i18nArray as $locale => $name) {
            if (empty($locale)) {
                throw new ValidationException("i18n keys must be non-empty locale strings in event '{$eventKey}'");
            }
            if (!is_string($name)) {
                throw new ValidationException("i18n.{$locale} must be a string in event '{$eventKey}'");
            }
        }
    }

    /**
     * Validate readings structure on an event.
     *
     * Expected structure for year-cycle events (SUNDAYS_SOLEMNITIES):
     * {
     *   "readings": {
     *     "en": {
     *       "annum_a": { ... readings matching ReadingsType DTO ... },
     *       "annum_b": { ... readings matching ReadingsType DTO ... },
     *       "annum_c": { ... readings matching ReadingsType DTO ... }
     *     }
     *   }
     * }
     *
     * Expected structure for flat-category events (WEEKDAYS_LENT, WEEKDAYS_EASTER, SANCTORUM):
     * {
     *   "readings": {
     *     "en": { ... readings matching ReadingsType DTO ... }
     *   }
     * }
     *
     * The readings structure within each annum (or directly for flat categories) must match
     * the expected DTO structure for the event, as determined by ReadingsType::forEventKey().
     * For example:
     * - Christmas: { vigil: {...}, night: {...}, dawn: {...}, day: {...} }
     * - EasterVigil: { first_reading, ..., epistle, gospel } (18 readings)
     * - PalmSun: { palm_gospel, first_reading, ..., gospel }
     * - Most events: { first_reading, responsorial_psalm, second_reading, gospel_acclamation, gospel }
     *
     * @param \stdClass $readings The readings object to validate.
     * @param string $eventKey The event key (for error messages and category determination).
     * @throws ValidationException If the structure is invalid.
     */
    private function validateEventReadings(\stdClass $readings, string $eventKey): void
    {
        $category     = LectionaryCategory::forEventKey($eventKey);
        $readingsType = ReadingsType::forEventKey($eventKey);

        /** @var array<string,mixed> $readingsArray */
        $readingsArray = get_object_vars($readings);
        foreach ($readingsArray as $locale => $localeReadings) {
            if (empty($locale)) {
                throw new ValidationException("readings keys must be non-empty locale strings in event '{$eventKey}'");
            }
            if (!( $localeReadings instanceof \stdClass )) {
                throw new ValidationException("readings.{$locale} must be an object in event '{$eventKey}'");
            }

            if ($category->hasYearCycle()) {
                // Year-cycle events must have annum_a, annum_b, annum_c
                if (
                    !property_exists($localeReadings, 'annum_a') ||
                    !property_exists($localeReadings, 'annum_b') ||
                    !property_exists($localeReadings, 'annum_c')
                ) {
                    throw new ValidationException(
                        "readings.{$locale} must have annum_a, annum_b, and annum_c properties in event '{$eventKey}'"
                    );
                }
                if (
                    !( $localeReadings->annum_a instanceof \stdClass ) ||
                    !( $localeReadings->annum_b instanceof \stdClass ) ||
                    !( $localeReadings->annum_c instanceof \stdClass )
                ) {
                    throw new ValidationException(
                        "readings.{$locale}.annum_[a|b|c] must be objects in event '{$eventKey}'"
                    );
                }

                // Validate each annum's structure against the expected DTO
                foreach (['annum_a', 'annum_b', 'annum_c'] as $annum) {
                    /** @var \stdClass $annumReadings */
                    $annumReadings = $localeReadings->$annum;
                    if (!$readingsType->validateStructure($annumReadings)) {
                        throw new ValidationException(
                            $readingsType->getValidationError($annumReadings)
                            . " in event '{$eventKey}' readings.{$locale}.{$annum}"
                        );
                    }
                }
            } else {
                // Flat-category events have readings directly under locale
                if (!$readingsType->validateStructure($localeReadings)) {
                    throw new ValidationException(
                        $readingsType->getValidationError($localeReadings)
                        . " in event '{$eventKey}' readings.{$locale}"
                    );
                }
            }
        }
    }

    /**
     * Extract i18n data from events array into locale-grouped structure.
     *
     * Transforms from per-event structure:
     * [{ "event_key": "Easter", "i18n": { "en": "Easter Sunday" } }]
     *
     * To per-locale structure:
     * { "en": { "Easter": "Easter Sunday" } }
     *
     * @param array<int,\stdClass> $events Array of event objects with i18n property.
     * @return \stdClass Object with locale keys and translation maps.
     */
    private function extractI18nFromEvents(array $events): \stdClass
    {
        $i18n = new \stdClass();

        foreach ($events as $event) {
            if (!property_exists($event, 'i18n') || !( $event->i18n instanceof \stdClass )) {
                continue;
            }

            /** @var string $eventKey */
            $eventKey = $event->event_key;

            /** @var array<string,string> $eventI18n */
            $eventI18n = get_object_vars($event->i18n);
            foreach ($eventI18n as $locale => $name) {
                if (!property_exists($i18n, $locale)) {
                    $i18n->{$locale} = new \stdClass();
                }
                $i18n->{$locale}->{$eventKey} = $name;
            }
        }

        return $i18n;
    }

    /**
     * Extract readings data from events array into locale-grouped structure.
     *
     * Transforms from per-event structure:
     * [{ "event_key": "Easter", "readings": { "en": { "annum_a": {...} } } }]
     *
     * To per-locale structure (compatible with writeLectionaryFiles):
     * { "en": { "Easter": { "annum_a": {...} } } }
     *
     * @param array<int,\stdClass> $events Array of event objects with readings property.
     * @return \stdClass Object with locale keys and event readings.
     */
    private function extractReadingsFromEvents(array $events): \stdClass
    {
        $readings = new \stdClass();

        foreach ($events as $event) {
            if (!property_exists($event, 'readings') || !( $event->readings instanceof \stdClass )) {
                continue;
            }

            /** @var string $eventKey */
            $eventKey = $event->event_key;

            /** @var array<string,\stdClass> $eventReadings */
            $eventReadings = get_object_vars($event->readings);
            foreach ($eventReadings as $locale => $localeReadings) {
                if (!property_exists($readings, $locale)) {
                    $readings->{$locale} = new \stdClass();
                }
                $readings->{$locale}->{$eventKey} = $localeReadings;
            }
        }

        return $readings;
    }

    /**
     * Strip i18n and readings properties from events for storage.
     *
     * The main temporale file stores only core event data (event_key, grade, type, color).
     * i18n and readings are stored in separate locale-specific files.
     *
     * @param array<int,\stdClass> $events Array of event objects.
     * @return array<int,\stdClass> Events with i18n and readings removed.
     */
    private function stripI18nAndReadingsFromEvents(array $events): array
    {
        $stripped = [];
        foreach ($events as $event) {
            $strippedEvent = clone $event;
            unset($strippedEvent->i18n, $strippedEvent->readings);
            $stripped[] = $strippedEvent;
        }
        return $stripped;
    }

    /**
     * Update i18n data in locale files (for PATCH - merge).
     *
     * @param \stdClass $i18n Object with locale keys and translation maps.
     */
    private function updateI18nFiles(\stdClass $i18n): void
    {
        $this->ensureI18nFolderExists();

        /** @var array<string,\stdClass> $i18nArray */
        $i18nArray = get_object_vars($i18n);
        foreach ($i18nArray as $locale => $translations) {
            $i18nFile = strtr(JsonData::TEMPORALE_I18N_FILE->path(), ['{locale}' => $locale]);

            // Load existing or start fresh
            $existingData = new \stdClass();
            if (file_exists($i18nFile) && is_file($i18nFile)) {
                try {
                    $existingData = Utilities::jsonFileToObject($i18nFile);
                } catch (\Throwable $e) {
                    // Start fresh if file is corrupted
                    $this->auditLogger->warning("Failed to read existing i18n file for locale '{$locale}', starting fresh", [
                        'file'  => $i18nFile,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Merge translations
            /** @var array<string,string> $translationsArray */
            $translationsArray = get_object_vars($translations);
            foreach ($translationsArray as $eventKey => $name) {
                $existingData->{$eventKey} = $name;
            }

            $jsonContent = json_encode($existingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($jsonContent === false) {
                throw new InternalServerErrorException("Failed to encode i18n data for locale '{$locale}'");
            }

            $result = file_put_contents($i18nFile, $jsonContent, LOCK_EX);
            if ($result === false) {
                throw new InternalServerErrorException("Failed to write i18n file for locale '{$locale}'");
            }

            Utilities::invalidateJsonFileCache($i18nFile);
        }
    }

    /**
     * Ensure all event_keys have entries in specified i18n files.
     *
     * Adds empty string as placeholder for missing entries.
     *
     * @param string[] $eventKeys The event keys that must exist.
     * @param string|null $skipLocale Locale to skip (already has translations).
     * @param string[]|null $onlyLocales If provided, only process these locales. If null, process all available locales.
     */
    private function ensureI18nConsistency(array $eventKeys, ?string $skipLocale = null, ?array $onlyLocales = null): void
    {
        $i18nFolder = JsonData::TEMPORALE_I18N_FOLDER->path();
        if (!is_dir($i18nFolder)) {
            return;
        }

        $localesToProcess = $onlyLocales ?? $this->availableLocales;

        foreach ($localesToProcess as $locale) {
            if ($locale === $skipLocale) {
                continue;
            }

            $i18nFile = strtr(JsonData::TEMPORALE_I18N_FILE->path(), ['{locale}' => $locale]);

            $i18nData = new \stdClass();
            if (file_exists($i18nFile) && is_file($i18nFile)) {
                try {
                    $i18nData = Utilities::jsonFileToObject($i18nFile);
                } catch (\Throwable $e) {
                    // Start fresh if corrupted
                    $this->auditLogger->warning("Failed to read i18n file for locale '{$locale}' during consistency check", [
                        'file'  => $i18nFile,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $modified = false;
            foreach ($eventKeys as $eventKey) {
                if (!property_exists($i18nData, $eventKey)) {
                    $i18nData->{$eventKey} = ''; // Empty string placeholder
                    $modified              = true;
                }
            }

            if ($modified) {
                $jsonContent = json_encode($i18nData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                if ($jsonContent === false) {
                    $this->auditLogger->warning("Failed to encode i18n data for locale '{$locale}' during consistency check", [
                        'file'       => $i18nFile,
                        'event_keys' => $eventKeys
                    ]);
                    continue;
                }

                $result = file_put_contents($i18nFile, $jsonContent, LOCK_EX);
                if ($result === false) {
                    $this->auditLogger->warning("Failed to write i18n file for locale '{$locale}' during consistency check", [
                        'file' => $i18nFile
                    ]);
                } else {
                    Utilities::invalidateJsonFileCache($i18nFile);
                }
            }
        }
    }

    /**
     * Write lectionary data to files (for PUT - full creation).
     *
     * @param \stdClass $lectionary Object with locale keys and event readings.
     * @throws InternalServerErrorException If unable to write to a file.
     */
    private function writeLectionaryFiles(\stdClass $lectionary): void
    {
        /** @var array<string,\stdClass> $lectionaryArray */
        $lectionaryArray = get_object_vars($lectionary);
        foreach ($lectionaryArray as $locale => $events) {
            /** @var array<string,\stdClass> $eventsArray */
            $eventsArray = get_object_vars($events);

            // Group events by category
            /** @var array<string,array<string,\stdClass>> $categorizedEvents */
            $categorizedEvents = [];
            foreach ($eventsArray as $eventKey => $readings) {
                $category = LectionaryCategory::forEventKey($eventKey);
                if (!isset($categorizedEvents[$category->value])) {
                    $categorizedEvents[$category->value] = [];
                }
                $categorizedEvents[$category->value][$eventKey] = $readings;
            }

            // Write to each category's file(s)
            foreach ($categorizedEvents as $categoryValue => $eventReadings) {
                $category = LectionaryCategory::from($categoryValue);

                if ($category->hasYearCycle()) {
                    // Write to separate year-cycle files (A, B, C)
                    foreach (['A', 'B', 'C'] as $year) {
                        $yearKey = 'annum_' . strtolower($year);
                        $file    = strtr($category->fileForYear($year)->path(), ['{locale}' => $locale]);

                        $this->ensureLectionaryFolderExists($category->folderForYear($year));

                        // Load existing or start fresh
                        $existingData = $this->loadLectionaryFileData($file);

                        // Merge new readings
                        foreach ($eventReadings as $eventKey => $readings) {
                            if (property_exists($readings, $yearKey)) {
                                $existingData->{$eventKey} = $readings->{$yearKey};
                            }
                        }

                        $this->saveLectionaryFile($file, $existingData, $locale);
                    }
                } else {
                    // Write to flat file
                    $file = strtr($category->file()->path(), ['{locale}' => $locale]);

                    $this->ensureLectionaryFolderExists($category->folder());

                    // Load existing or start fresh
                    $existingData = $this->loadLectionaryFileData($file);

                    // Merge new readings
                    foreach ($eventReadings as $eventKey => $readings) {
                        $existingData->{$eventKey} = $readings;
                    }

                    $this->saveLectionaryFile($file, $existingData, $locale);
                }
            }
        }
    }

    /**
     * Update lectionary data in files (for PATCH - merge).
     *
     * Note: This uses the same logic as writeLectionaryFiles() because both
     * operations merge new readings into existing file data rather than replacing.
     *
     * @param \stdClass $lectionary Object with locale keys and event readings.
     */
    private function updateLectionaryFiles(\stdClass $lectionary): void
    {
        $this->writeLectionaryFiles($lectionary);
    }

    /**
     * Remove an event_key from all lectionary files.
     *
     * @param string $eventKey The event key to remove from lectionary files.
     */
    private function removeEventKeyFromLectionaryFiles(string $eventKey): void
    {
        $category = LectionaryCategory::forEventKey($eventKey);

        if ($category->hasYearCycle()) {
            // Remove from all three-year cycle files (A, B, C)
            foreach (['A', 'B', 'C'] as $year) {
                $folder = $category->folderForYear($year)->path();
                if (!is_dir($folder)) {
                    continue;
                }

                $files = glob($folder . '/*.json');
                if ($files === false) {
                    continue;
                }

                foreach ($files as $file) {
                    $this->removeEventKeyFromLectionaryFile($file, $eventKey);
                }
            }
        } elseif ($category->hasTwoYearCycle()) {
            // Remove from all two-year cycle files (I, II)
            foreach (['I', 'II'] as $year) {
                $folder = $category->folderForTwoYearCycle($year)->path();
                if (!is_dir($folder)) {
                    continue;
                }

                $files = glob($folder . '/*.json');
                if ($files === false) {
                    continue;
                }

                foreach ($files as $file) {
                    $this->removeEventKeyFromLectionaryFile($file, $eventKey);
                }
            }
        } else {
            // Remove from flat file for all locales
            $folder = $category->folder()->path();
            if (!is_dir($folder)) {
                return;
            }

            $files = glob($folder . '/*.json');
            if ($files === false) {
                return;
            }

            foreach ($files as $file) {
                $this->removeEventKeyFromLectionaryFile($file, $eventKey);
            }
        }
    }

    /**
     * Remove an event_key from a specific lectionary file.
     *
     * @param string $file The file path.
     * @param string $eventKey The event key to remove.
     */
    private function removeEventKeyFromLectionaryFile(string $file, string $eventKey): void
    {
        if (!file_exists($file) || !is_file($file)) {
            return;
        }

        try {
            $data = Utilities::jsonFileToObject($file);
        } catch (\JsonException | ServiceUnavailableException $e) {
            $this->auditLogger->warning('Failed to read lectionary file for event removal', [
                'file'      => $file,
                'event_key' => $eventKey,
                'error'     => $e->getMessage()
            ]);
            return;
        }

        if (!property_exists($data, $eventKey)) {
            return; // Event not in this file
        }

        unset($data->{$eventKey});

        $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($jsonContent === false) {
            $this->auditLogger->warning('Failed to encode lectionary data after removing event', [
                'file'      => $file,
                'event_key' => $eventKey
            ]);
            return;
        }

        $result = file_put_contents($file, $jsonContent, LOCK_EX);
        if ($result === false) {
            $this->auditLogger->warning('Failed to write lectionary file after removing event', [
                'file'      => $file,
                'event_key' => $eventKey
            ]);
        } else {
            Utilities::invalidateJsonFileCache($file);
        }
    }

    /**
     * Ensure a lectionary folder exists.
     *
     * @param JsonData $folderEnum The folder enum to ensure exists.
     * @throws InternalServerErrorException If unable to create the directory.
     */
    private function ensureLectionaryFolderExists(JsonData $folderEnum): void
    {
        $folder = $folderEnum->path();
        if (!is_dir($folder)) {
            if (!@mkdir($folder, 0755, true) && !is_dir($folder)) {
                throw new InternalServerErrorException('Failed to create lectionary directory: ' . $folder);
            }
        }
    }

    /**
     * Load lectionary file data, returning empty object if file doesn't exist or is invalid.
     *
     * @param string $file The file path.
     * @return \stdClass The loaded data or empty object.
     */
    private function loadLectionaryFileData(string $file): \stdClass
    {
        if (!file_exists($file) || !is_file($file)) {
            return new \stdClass();
        }

        try {
            return Utilities::jsonFileToObject($file);
        } catch (\JsonException | ServiceUnavailableException $e) {
            $this->auditLogger->debug('Failed to load lectionary file, starting fresh', [
                'file'  => $file,
                'error' => $e->getMessage()
            ]);
            return new \stdClass();
        }
    }

    /**
     * Save lectionary file data.
     *
     * @param string $file The file path.
     * @param \stdClass $data The data to save.
     * @param string $locale The locale (for error messages).
     * @throws InternalServerErrorException If unable to save the file.
     */
    private function saveLectionaryFile(string $file, \stdClass $data, string $locale): void
    {
        $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($jsonContent === false) {
            throw new InternalServerErrorException("Failed to encode lectionary data for locale '{$locale}'");
        }

        $result = file_put_contents($file, $jsonContent, LOCK_EX);
        if ($result === false) {
            throw new InternalServerErrorException("Failed to write lectionary file for locale '{$locale}'");
        }

        Utilities::invalidateJsonFileCache($file);
    }

    /**
     * Validates a temporale event object.
     *
     * @param \stdClass $event The event object to validate
     * @throws ValidationException if the event is invalid
     */
    private function validateTemporaleEvent(\stdClass $event): void
    {
        if (!property_exists($event, 'event_key') || !is_string($event->event_key)) {
            throw new ValidationException('Event must have a string event_key property');
        }
        if ($event->event_key === '') {
            throw new ValidationException('Event event_key cannot be empty');
        }

        if (!property_exists($event, 'grade') || !is_int($event->grade)) {
            throw new ValidationException('Event must have an integer grade property');
        }

        // Validate grade is within canonical range (0=WEEKDAY to 7=HIGHER_SOLEMNITY)
        if ($event->grade < 0 || $event->grade > 7) {
            throw new ValidationException("Event grade must be between 0 and 7, got {$event->grade}");
        }

        if (!property_exists($event, 'type') || !is_string($event->type)) {
            throw new ValidationException('Event must have a string type property');
        }

        if (!in_array($event->type, ['mobile', 'fixed'], true)) {
            throw new ValidationException('Event type must be either "mobile" or "fixed"');
        }

        if (!property_exists($event, 'color') || !is_array($event->color)) {
            throw new ValidationException('Event must have an array color property');
        }

        $validColors = LitColor::values();
        foreach ($event->color as $color) {
            if (!is_string($color)) {
                throw new ValidationException('Each color must be a string');
            }
            if (!in_array($color, $validColors, true)) {
                throw new ValidationException("Invalid color '{$color}'. Valid colors are: " . implode(', ', $validColors));
            }
        }
    }
}
