<?php

namespace LiturgicalCalendar\Api\Handlers\Auth;

use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestContentType;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Logout Handler
 *
 * Handles POST /auth/logout requests
 *
 * This is a stateless logout endpoint. Since JWT tokens are stateless,
 * the actual token invalidation happens client-side by deleting the stored tokens.
 * This endpoint provides a consistent API for logout operations and can be
 * extended in the future to support token blacklisting if needed.
 *
 * Returns:
 * - message (string) - Success message
 *
 * @package LiturgicalCalendar\Api\Handlers\Auth
 */
final class LogoutHandler extends AbstractHandler
{
    /**
     * Initialize the logout handler with allowed methods and accepted content types.
     *
     * Sets the handler to accept only POST requests with JSON accept and content-type headers.
     */
    public function __construct()
    {
        parent::__construct();

        // Only allow POST method
        $this->allowedRequestMethods = [RequestMethod::POST];

        // Only accept JSON
        $this->allowedAcceptHeaders       = [AcceptHeader::JSON];
        $this->allowedRequestContentTypes = [RequestContentType::JSON];
    }

    /**
     * Process a logout request and return a success response.
     *
     * Since JWTs are stateless, this endpoint simply returns a success message.
     * The client is responsible for deleting the tokens from storage.
     *
     * @param ServerRequestInterface $request The incoming HTTP request.
     * @return ResponseInterface Response with a JSON body containing a success message.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Initialize response
        $response = static::initResponse($request);

        $method = RequestMethod::from($request->getMethod());

        // Handle OPTIONS for CORS preflight
        if ($method === RequestMethod::OPTIONS) {
            return $this->handlePreflightRequest($request, $response);
        } else {
            $response = $this->setAccessControlAllowOriginHeader($request, $response);
        }

        // Validate request method
        $this->validateRequestMethod($request);

        // Validate Accept header
        $mime     = $this->validateAcceptHeader($request, AcceptabilityLevel::LAX);
        $response = $response->withHeader('Content-Type', $mime);

        // Prepare response data
        $responseData = [
            'message' => 'Logged out successfully'
        ];

        // Encode response (encodeResponseBody sets status to 200 OK by default)
        return $this->encodeResponseBody($response, $responseData);
    }
}
