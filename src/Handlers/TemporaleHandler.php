<?php

namespace LiturgicalCalendar\Api\Handlers;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Handlers\Auth\ClientIpTrait;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
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
                    $locale                   = basename($file, '.json');
                    $this->availableLocales[] = $locale;
                }
            }
        }
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

        // Check Accept-Language header for locale
        $locale = Negotiator::pickLanguage($request, [], LitLocale::LATIN);
        if ($locale && LitLocale::isValid($locale)) {
            // Check if we have a translation file for this locale
            $baseLocale = explode('_', $locale)[0];
            if (in_array($locale, $this->availableLocales, true)) {
                $this->locale = $locale;
            } elseif (in_array($baseLocale, $this->availableLocales, true)) {
                $this->locale = $baseLocale;
            } else {
                $this->locale = LitLocale::LATIN_PRIMARY_LANGUAGE;
            }
        } else {
            $this->locale = LitLocale::LATIN_PRIMARY_LANGUAGE;
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
     * Returns the temporale events with translated names based on the locale.
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

        $response = $response->withHeader('X-Litcal-Temporale-Locale', $this->locale);
        return $this->encodeResponseBody($response, $temporaleRows);
    }

    /**
     * Handle PUT requests to replace the entire temporale data.
     *
     * Requires JWT authentication (handled by middleware).
     */
    private function handlePutRequest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = $this->parseBodyPayload($request, false);

        if (!is_array($payload)) {
            throw new ValidationException('Request body must be an array of temporale events');
        }

        // Validate each event in the payload and check for duplicate event_keys
        /** @var array<string,bool> $seenEventKeys */
        $seenEventKeys = [];
        foreach ($payload as $event) {
            if (!( $event instanceof \stdClass )) {
                throw new ValidationException('Each event must be an object');
            }
            $this->validateTemporaleEvent($event);

            // Check for duplicate event_keys (event_key is validated as string by validateTemporaleEvent)
            /** @var string $eventKey */
            $eventKey = $event->event_key;
            if (isset($seenEventKeys[$eventKey])) {
                throw new ValidationException("Duplicate event_key '{$eventKey}' in payload");
            }
            $seenEventKeys[$eventKey] = true;
        }

        $temporaleFile = JsonData::TEMPORALE_FILE->path();
        $jsonContent   = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($jsonContent === false) {
            throw new ValidationException('Failed to encode temporale data as JSON');
        }

        $result = file_put_contents($temporaleFile, $jsonContent);
        if ($result === false) {
            throw new ValidationException('Failed to write temporale data to file');
        }

        // Log the operation
        $this->auditLogger->info('Temporale data replaced', [
            'operation' => 'PUT',
            'client_ip' => $this->clientIp,
            'file'      => $temporaleFile,
            'events'    => count($payload)
        ]);

        return $this->encodeResponseBody($response, [
            'success' => true,
            'message' => 'Temporale data replaced successfully',
            'events'  => count($payload)
        ], StatusCode::OK);
    }

    /**
     * Handle PATCH requests to update specific temporale events.
     *
     * Requires JWT authentication (handled by middleware).
     */
    private function handlePatchRequest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = $this->parseBodyPayload($request, false);

        if (!is_array($payload)) {
            throw new ValidationException('Request body must be an array of temporale events to update');
        }

        // Load existing data
        $temporaleFile = JsonData::TEMPORALE_FILE->path();
        if (!file_exists($temporaleFile)) {
            throw new NotFoundException('Temporale data file not found');
        }

        $existingData = Utilities::jsonFileToObjectArray($temporaleFile);

        // Build a map of existing events by event_key
        /** @var array<string,int> $eventKeyToIndex */
        $eventKeyToIndex = [];
        foreach ($existingData as $idx => $event) {
            if (property_exists($event, 'event_key') && is_string($event->event_key)) {
                $eventKeyToIndex[$event->event_key] = (int) $idx;
            }
        }

        $updatedCount = 0;
        $addedCount   = 0;

        // Process each event in the payload
        foreach ($payload as $event) {
            if (!( $event instanceof \stdClass )) {
                throw new ValidationException('Each event must be an object');
            }
            if (!property_exists($event, 'event_key') || !is_string($event->event_key)) {
                throw new ValidationException('Each event must have an event_key property');
            }

            $this->validateTemporaleEvent($event);

            /** @var string $eventKey */
            $eventKey = $event->event_key;
            if (isset($eventKeyToIndex[$eventKey])) {
                // Update existing event
                $existingData[$eventKeyToIndex[$eventKey]] = $event;
                $updatedCount++;
            } else {
                // Add new event
                $existingData[] = $event;
                $addedCount++;
            }
        }

        $jsonContent = json_encode(array_values($existingData), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($jsonContent === false) {
            throw new ValidationException('Failed to encode temporale data as JSON');
        }

        $result = file_put_contents($temporaleFile, $jsonContent);
        if ($result === false) {
            throw new ValidationException('Failed to write temporale data to file');
        }

        // Log the operation
        $this->auditLogger->info('Temporale data updated', [
            'operation' => 'PATCH',
            'client_ip' => $this->clientIp,
            'file'      => $temporaleFile,
            'updated'   => $updatedCount,
            'added'     => $addedCount
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

        // Find and remove the event
        /** @var int|null $foundIndex */
        $foundIndex = null;
        foreach ($existingData as $idx => $event) {
            if (property_exists($event, 'event_key') && $event->event_key === $eventKey) {
                $foundIndex = (int) $idx;
                break;
            }
        }

        if ($foundIndex === null) {
            throw new NotFoundException("Temporale event with key '{$eventKey}' not found");
        }

        // Remove the event
        array_splice($existingData, $foundIndex, 1);

        $jsonContent = json_encode(array_values($existingData), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($jsonContent === false) {
            throw new ValidationException('Failed to encode temporale data as JSON');
        }

        $result = file_put_contents($temporaleFile, $jsonContent);
        if ($result === false) {
            throw new ValidationException('Failed to write temporale data to file');
        }

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

        if (!property_exists($event, 'grade') || !is_int($event->grade)) {
            throw new ValidationException('Event must have an integer grade property');
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
