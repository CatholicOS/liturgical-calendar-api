<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Http\Middleware;

use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Repositories\CalendarPermissionRepository;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Authorization Middleware for role and permission checks.
 *
 * This middleware runs after OidcAuthMiddleware and validates:
 * 1. User has required Zitadel role
 * 2. User has calendar-specific permission (if applicable)
 *
 * Admin users bypass all checks.
 */
class AuthorizationMiddleware implements MiddlewareInterface
{
    private CalendarPermissionRepository $permissionRepo;

    /**
     * Required Zitadel role.
     */
    private string $requiredRole;

    /**
     * Calendar type for permission check (null = no calendar check).
     */
    private ?string $calendarType;

    /**
     * Request attribute name for calendar ID.
     */
    private string $calendarIdAttribute;

    /**
     * Permission level required.
     */
    private string $permissionLevel;

    /**
     * Create authorization middleware.
     *
     * @param CalendarPermissionRepository $permissionRepo Permission repository
     * @param string $requiredRole Required Zitadel role (e.g., 'calendar_editor')
     * @param string|null $calendarType Calendar type for permission check (null = no check)
     * @param string $calendarIdAttribute Request attribute name containing calendar ID
     * @param string $permissionLevel Required permission level ('read' or 'write')
     */
    public function __construct(
        CalendarPermissionRepository $permissionRepo,
        string $requiredRole,
        ?string $calendarType = null,
        string $calendarIdAttribute = 'calendar_id',
        string $permissionLevel = 'write'
    ) {
        $this->permissionRepo      = $permissionRepo;
        $this->requiredRole        = $requiredRole;
        $this->calendarType        = $calendarType;
        $this->calendarIdAttribute = $calendarIdAttribute;
        $this->permissionLevel     = $permissionLevel;
    }

    /**
     * Process the request and check authorization.
     *
     * @param ServerRequestInterface $request Incoming request
     * @param RequestHandlerInterface $handler Next handler
     * @return ResponseInterface Response from next handler
     * @throws UnauthorizedException If user is not authenticated
     * @throws ForbiddenException If user lacks required role or permission
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var array|null $oidcUser */
        $oidcUser = $request->getAttribute('oidc_user');

        if ($oidcUser === null) {
            throw new UnauthorizedException('Not authenticated');
        }

        $userId = $oidcUser['sub'] ?? null;
        if ($userId === null) {
            throw new UnauthorizedException('Invalid user token');
        }

        $roles = $oidcUser['roles'] ?? [];

        // Admin users bypass all checks
        if (in_array('admin', $roles, true)) {
            return $handler->handle($request);
        }

        // Check required role
        if (!in_array($this->requiredRole, $roles, true)) {
            throw new ForbiddenException(
                sprintf('Missing required role: %s', $this->requiredRole)
            );
        }

        // Check calendar-specific permission if applicable
        if ($this->calendarType !== null) {
            $calendarId = $this->extractCalendarId($request);

            if ($calendarId !== null) {
                $hasPermission = $this->permissionRepo->hasPermission(
                    $userId,
                    $this->calendarType,
                    $calendarId,
                    $this->permissionLevel
                );

                if (!$hasPermission) {
                    throw new ForbiddenException(
                        sprintf(
                            'No %s permission for %s calendar: %s',
                            $this->permissionLevel,
                            $this->calendarType,
                            $calendarId
                        )
                    );
                }
            }
        }

        return $handler->handle($request);
    }

    /**
     * Extract calendar ID from request.
     *
     * Looks in request attributes first, then route parameters, then query params.
     *
     * @param ServerRequestInterface $request The request
     * @return string|null Calendar ID or null if not found
     */
    private function extractCalendarId(ServerRequestInterface $request): ?string
    {
        // Check request attribute (set by router)
        $calendarId = $request->getAttribute($this->calendarIdAttribute);
        if ($calendarId !== null) {
            return (string) $calendarId;
        }

        // Check query parameters
        $queryParams = $request->getQueryParams();
        if (isset($queryParams[$this->calendarIdAttribute])) {
            return (string) $queryParams[$this->calendarIdAttribute];
        }

        // For POST/PUT/PATCH, check parsed body
        $parsedBody = $request->getParsedBody();
        if (is_array($parsedBody) && isset($parsedBody[$this->calendarIdAttribute])) {
            return (string) $parsedBody[$this->calendarIdAttribute];
        }

        return null;
    }

    /**
     * Create middleware for calendar editor role with calendar permission.
     *
     * @param CalendarPermissionRepository $permissionRepo Permission repository
     * @param string $calendarType Calendar type (national, diocesan, widerregion)
     * @return self Configured middleware instance
     */
    public static function forCalendarEditor(
        CalendarPermissionRepository $permissionRepo,
        string $calendarType
    ): self {
        return new self(
            $permissionRepo,
            'calendar_editor',
            $calendarType,
            'calendar_id',
            'write'
        );
    }

    /**
     * Create middleware for developer role.
     *
     * @param CalendarPermissionRepository $permissionRepo Permission repository
     * @return self Configured middleware instance
     */
    public static function forDeveloper(CalendarPermissionRepository $permissionRepo): self
    {
        return new self(
            $permissionRepo,
            'developer',
            null,
            'calendar_id',
            'read'
        );
    }

    /**
     * Create middleware for test editor role.
     *
     * @param CalendarPermissionRepository $permissionRepo Permission repository
     * @return self Configured middleware instance
     */
    public static function forTestEditor(CalendarPermissionRepository $permissionRepo): self
    {
        return new self(
            $permissionRepo,
            'test_editor',
            null,
            'calendar_id',
            'write'
        );
    }

    /**
     * Create middleware for admin-only access.
     *
     * @param CalendarPermissionRepository $permissionRepo Permission repository
     * @return self Configured middleware instance
     */
    public static function forAdmin(CalendarPermissionRepository $permissionRepo): self
    {
        return new self(
            $permissionRepo,
            'admin',
            null,
            'calendar_id',
            'write'
        );
    }
}
