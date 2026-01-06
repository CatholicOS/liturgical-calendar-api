<?php

namespace LiturgicalCalendar\Api\Handlers;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitLocale;
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
        // Initialize audit logger for write operations
        $this->auditLogger = LoggerFactory::create('audit', null, 90, false, true, false);
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
     * Uses Year A folder as reference since all years should have the same locales.
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
        return is_array($localeParts) ? $localeParts[0] : $locale;
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
     */
    private function handleGetRequest(ResponseInterface $response): ResponseInterface
    {
        $temporaleFile = JsonData::TEMPORALE_FILE->path();
        if (!file_exists($temporaleFile)) {
            throw new NotFoundException('Temporale data file not found');
        }

        $temporaleRows = Utilities::jsonFileToObjectArray($temporaleFile);

        // Load translations
        $i18nFile = strtr(JsonData::TEMPORALE_I18N_FILE->path(), ['{locale}' => $this->locale]);
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

                // Check ferial lectionaries (Lent/Easter weekdays) - flat structure, no year cycles
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
        }

        $response = $response->withHeader('X-Litcal-Temporale-Locale', $this->locale);
        return $this->encodeResponseBody($response, [
            'events' => $temporaleRows,
            'locale' => $this->locale
        ]);
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

        // Lent weekdays (includes Ash Wednesday, Holy Week days)
        $lentFile = strtr(JsonData::LECTIONARY_WEEKDAYS_LENT_FILE->path(), ['{locale}' => $locale]);
        if (file_exists($lentFile)) {
            try {
                $lentData = Utilities::jsonFileToObject($lentFile);
                foreach (get_object_vars($lentData) as $key => $value) {
                    $ferialData->{$key} = $value;
                }
            } catch (\JsonException | ServiceUnavailableException $e) {
                // Skip if file cannot be parsed or is unavailable
                $this->auditLogger->debug("Failed to load Lent weekdays lectionary for locale '{$locale}'", [
                    'file'  => $lentFile,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Easter weekdays (includes Easter Octave days)
        $easterFile = strtr(JsonData::LECTIONARY_WEEKDAYS_EASTER_FILE->path(), ['{locale}' => $locale]);
        if (file_exists($easterFile)) {
            try {
                $easterData = Utilities::jsonFileToObject($easterFile);
                foreach (get_object_vars($easterData) as $key => $value) {
                    $ferialData->{$key} = $value;
                }
            } catch (\JsonException | ServiceUnavailableException $e) {
                // Skip if file cannot be parsed or is unavailable
                $this->auditLogger->debug("Failed to load Easter weekdays lectionary for locale '{$locale}'", [
                    'file'  => $easterFile,
                    'error' => $e->getMessage()
                ]);
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
     * Expected payload structure:
     * {
     *   "events": [...],
     *   "locales": ["en", "la", "de", ...],
     *   "i18n": { "en": { "Easter": "Easter Sunday", ... } }
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
            throw new ValidationException('Request body must be an object with events, locales, and i18n properties');
        }

        // Validate required properties
        if (!property_exists($payload, 'events') || !is_array($payload->events)) {
            throw new ValidationException('Payload must have an "events" array property');
        }
        if (!property_exists($payload, 'locales') || !is_array($payload->locales)) {
            throw new ValidationException('Payload must have a "locales" array property');
        }
        if (!property_exists($payload, 'i18n') || !( $payload->i18n instanceof \stdClass )) {
            throw new ValidationException('Payload must have an "i18n" object property');
        }

        /** @var array<int,mixed> $events */
        $events = $payload->events;

        /** @var array<int,mixed> $locales */
        $locales = $payload->locales;

        /** @var \stdClass $i18n */
        $i18n = $payload->i18n;

        // Validate locales are base locale strings
        foreach ($locales as $locale) {
            if (!is_string($locale) || empty($locale)) {
                throw new ValidationException('Each locale must be a non-empty string');
            }
            if (str_contains($locale, '_') || str_contains($locale, '-')) {
                throw new ValidationException("Locale '{$locale}' must be a base locale without regional identifiers");
            }
        }

        // Validate i18n structure
        $this->validateI18n($i18n);

        // Determine Accept-Language locale (base locale only)
        $acceptLocale = $this->getBaseLocale($this->locale);

        // Validate that i18n contains the Accept-Language locale
        if (!property_exists($i18n, $acceptLocale)) {
            throw new ValidationException("i18n must contain translations for the Accept-Language locale '{$acceptLocale}'");
        }

        // Validate each event and collect event_keys
        /** @var array<string,bool> $seenEventKeys */
        $seenEventKeys = [];
        /** @var string[] $eventKeys */
        $eventKeys = [];
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
            $eventKeys[]              = $eventKey;
        }

        // Validate that i18n[acceptLocale] contains ALL event_keys
        /** @var \stdClass $acceptLocaleI18n */
        $acceptLocaleI18n = $i18n->{$acceptLocale};
        foreach ($eventKeys as $eventKey) {
            if (!property_exists($acceptLocaleI18n, $eventKey)) {
                throw new ValidationException(
                    "i18n.{$acceptLocale} must contain translation for event_key '{$eventKey}'"
                );
            }
        }

        // Build i18n data for all locales
        $i18nToWrite = new \stdClass();
        /** @var string $locale */
        foreach ($locales as $locale) {
            $i18nToWrite->{$locale} = new \stdClass();
            /** @var \stdClass|null $localeTranslations */
            $localeTranslations = property_exists($i18n, $locale) ? $i18n->{$locale} : null;
            foreach ($eventKeys as $eventKey) {
                if ($localeTranslations instanceof \stdClass && property_exists($localeTranslations, $eventKey)) {
                    $i18nToWrite->{$locale}->{$eventKey} = $localeTranslations->{$eventKey};
                } else {
                    // Empty string placeholder for missing translations
                    $i18nToWrite->{$locale}->{$eventKey} = '';
                }
            }
        }

        // Write i18n files for each locale
        $this->writeI18nFiles($i18nToWrite);

        // Write events to main temporale file
        $jsonContent = json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
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
        /** @var string[] $localesArray */
        $localesArray           = $locales;
        $this->availableLocales = array_values(array_unique(array_merge($this->availableLocales, $localesArray)));

        // Log the operation
        $this->auditLogger->info('Temporale data created', [
            'operation'    => 'PUT',
            'client_ip'    => $this->clientIp,
            'file'         => $temporaleFile,
            'events'       => count($events),
            'i18n_locales' => $locales
        ]);

        return $this->encodeResponseBody($response, [
            'success' => true,
            'message' => 'Temporale data created successfully',
            'events'  => count($events)
        ], StatusCode::CREATED);
    }

    /**
     * Handle PATCH requests to update specific temporale events.
     *
     * Requires JWT authentication (handled by middleware).
     *
     * Expected payload structure:
     * {
     *   "events": [...],
     *   "i18n": { "en": { "NewEvent": "New Event Name", ... } }  // optional
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

        // i18n is optional for PATCH
        $hasI18n = property_exists($payload, 'i18n') && $payload->i18n instanceof \stdClass;
        /** @var \stdClass|null $i18n */
        $i18n = $hasI18n ? $payload->i18n : null;

        if ($i18n !== null) {
            $this->validateI18n($i18n);
        }

        // Load existing data
        $temporaleFile = JsonData::TEMPORALE_FILE->path();
        if (!file_exists($temporaleFile)) {
            throw new NotFoundException('Temporale data file not found');
        }

        $existingData = Utilities::jsonFileToObjectArray($temporaleFile);

        // Check for duplicate event_keys in the payload
        /** @var array<string,bool> $seenEventKeys */
        $seenEventKeys = [];
        foreach ($events as $event) {
            if ($event instanceof \stdClass && property_exists($event, 'event_key') && is_string($event->event_key)) {
                if (isset($seenEventKeys[$event->event_key])) {
                    throw new ValidationException("Duplicate event_key '{$event->event_key}' in payload");
                }
                $seenEventKeys[$event->event_key] = true;
            }
        }

        // Build a map of existing events by event_key
        /** @var array<string,int> $existingEventKeyToIndex */
        $existingEventKeyToIndex = [];
        foreach ($existingData as $idx => $event) {
            if (property_exists($event, 'event_key') && is_string($event->event_key)) {
                $existingEventKeyToIndex[$event->event_key] = (int) $idx;
            }
        }

        $updatedCount = 0;
        $addedCount   = 0;
        /** @var string[] $newEventKeys */
        $newEventKeys = [];

        // Determine Accept-Language locale (base locale only)
        $acceptLocale = $this->getBaseLocale($this->locale);

        // Process each event in the payload
        foreach ($events as $event) {
            if (!( $event instanceof \stdClass )) {
                throw new ValidationException('Each event must be an object');
            }
            if (!property_exists($event, 'event_key') || !is_string($event->event_key)) {
                throw new ValidationException('Each event must have an event_key property');
            }

            $this->validateTemporaleEvent($event);

            /** @var string $eventKey */
            $eventKey = $event->event_key;
            if (isset($existingEventKeyToIndex[$eventKey])) {
                // Update existing event
                $existingData[$existingEventKeyToIndex[$eventKey]] = $event;
                $updatedCount++;
            } else {
                // Add new event
                $existingData[] = $event;
                $newEventKeys[] = $eventKey;
                $addedCount++;
            }
        }

        // For new event_keys, require i18n for Accept-Language locale
        if (count($newEventKeys) > 0) {
            if ($i18n === null) {
                throw new ValidationException(
                    'i18n property is required when adding new events. ' .
                    'Missing translations for new event_keys: ' . implode(', ', $newEventKeys)
                );
            }
            if (!property_exists($i18n, $acceptLocale)) {
                throw new ValidationException(
                    "i18n must contain translations for Accept-Language locale '{$acceptLocale}' for new events"
                );
            }
            /** @var \stdClass $acceptLocaleI18n */
            $acceptLocaleI18n = $i18n->{$acceptLocale};
            foreach ($newEventKeys as $eventKey) {
                if (!property_exists($acceptLocaleI18n, $eventKey)) {
                    throw new ValidationException(
                        "i18n.{$acceptLocale} must contain translation for new event_key '{$eventKey}'"
                    );
                }
            }
        }

        // Update i18n files if i18n provided
        if ($i18n !== null) {
            $this->updateI18nFiles($i18n);

            // Merge newly created locales into availableLocales so ensureI18nConsistency can process them
            $i18nLocalesFromPayload = array_keys(get_object_vars($i18n));
            $this->availableLocales = array_values(array_unique(array_merge($this->availableLocales, $i18nLocalesFromPayload)));
        }

        // Ensure all new event_keys have entries in all i18n files (empty string placeholder)
        if (count($newEventKeys) > 0) {
            $this->ensureI18nConsistency($newEventKeys, $acceptLocale);
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
        $i18nLocales = $i18n !== null ? array_keys(get_object_vars($i18n)) : [];
        $this->auditLogger->info('Temporale data updated', [
            'operation'    => 'PATCH',
            'client_ip'    => $this->clientIp,
            'file'         => $temporaleFile,
            'updated'      => $updatedCount,
            'added'        => $addedCount,
            'i18n_locales' => $i18nLocales
        ]);

        return $this->encodeResponseBody($response, [
            'success' => true,
            'message' => 'Temporale data updated successfully',
            'updated' => $updatedCount,
            'added'   => $addedCount
        ], StatusCode::OK);
    }

    /**
     * Handle DELETE requests to remove a specific temporale event.
     *
     * Requires JWT authentication (handled by middleware).
     * The event_key is expected as a path parameter: DELETE /temporale/{event_key}
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

        if ($foundIndex === null) {
            throw new NotFoundException("Temporale event with key '{$eventKey}' not found");
        }

        // Remove the event
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
     * Validate i18n structure in payload.
     *
     * @param \stdClass $i18n The i18n object to validate.
     * @throws ValidationException If the structure is invalid.
     */
    private function validateI18n(\stdClass $i18n): void
    {
        /** @var array<string,mixed> $i18nArray */
        $i18nArray = get_object_vars($i18n);
        foreach ($i18nArray as $locale => $translations) {
            if (empty($locale)) {
                throw new ValidationException('i18n keys must be non-empty locale strings');
            }
            if (!( $translations instanceof \stdClass )) {
                throw new ValidationException("i18n.{$locale} must be an object");
            }
            /** @var array<string,mixed> $translationsArray */
            $translationsArray = get_object_vars($translations);
            foreach ($translationsArray as $eventKey => $name) {
                if (!is_string($name)) {
                    throw new ValidationException("i18n.{$locale}.{$eventKey} must be a string");
                }
            }
        }
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
     * Ensure all event_keys have entries in all i18n files.
     *
     * Adds empty string as placeholder for missing entries.
     *
     * @param string[] $eventKeys The event keys that must exist.
     * @param string|null $skipLocale Locale to skip (already has translations).
     */
    private function ensureI18nConsistency(array $eventKeys, ?string $skipLocale = null): void
    {
        $i18nFolder = JsonData::TEMPORALE_I18N_FOLDER->path();
        if (!is_dir($i18nFolder)) {
            return;
        }

        foreach ($this->availableLocales as $locale) {
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
