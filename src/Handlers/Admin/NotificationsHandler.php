<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Admin;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Middleware\OidcAuthMiddleware;
use LiturgicalCalendar\Api\Repositories\RoleRequestRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Notifications Handler
 *
 * Returns notification counts for admin users.
 * GET /admin/notifications - Get counts of pending items
 *
 * Requires admin role.
 */
final class NotificationsHandler extends AbstractHandler
{
    private ?RoleRequestRepository $roleRequestRepo = null;

    public function __construct()
    {
        parent::__construct();

        $this->allowedRequestMethods = [RequestMethod::GET];
        $this->allowedAcceptHeaders  = [AcceptHeader::JSON];
        $this->allowCredentials      = true;
    }

    private function getRoleRequestRepository(): RoleRequestRepository
    {
        if ($this->roleRequestRepo === null) {
            $this->roleRequestRepo = new RoleRequestRepository();
        }
        return $this->roleRequestRepo;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = static::initResponse($request);
        $method   = RequestMethod::from($request->getMethod());

        if ($method === RequestMethod::OPTIONS) {
            return $this->handlePreflightRequest($request, $response);
        }

        $response = $this->setAccessControlAllowOriginHeader($request, $response);
        $this->validateRequestMethod($request);

        $mime     = $this->validateAcceptHeader($request, AcceptabilityLevel::LAX);
        $response = $response->withHeader('Content-Type', $mime);

        // Check authentication via OIDC token
        /** @var array{sub?: string, roles?: array<string>}|null $oidcUser */
        $oidcUser = $request->getAttribute('oidc_user');

        if ($oidcUser === null) {
            throw new UnauthorizedException('Authentication required');
        }

        // Verify admin role
        if (!OidcAuthMiddleware::isAdmin($oidcUser)) {
            throw new ForbiddenException('Admin role required');
        }

        // Get notification counts
        $notifications = [
            'pending_role_requests'       => 0,
            'pending_permission_requests' => 0,
            'total'                       => 0,
            'items'                       => [],
        ];

        if (Connection::isConfigured()) {
            // Get role request counts
            $roleRequestRepo = $this->getRoleRequestRepository();
            $counts          = $roleRequestRepo->getRequestCounts();

            $notifications['pending_role_requests'] = $counts['pending'];

            // Get recent pending role requests for the dropdown
            $pendingRequests = $roleRequestRepo->getPendingRequests();
            foreach (array_slice($pendingRequests, 0, 5) as $req) {
                $notifications['items'][] = [
                    'type'       => 'role_request',
                    'id'         => $req['id'] ?? '',
                    'user_name'  => $req['user_name'] ?? $req['user_email'] ?? 'Unknown',
                    'user_email' => $req['user_email'] ?? '',
                    'role'       => $req['requested_role'] ?? '',
                    'created_at' => $req['created_at'] ?? '',
                    'url'        => 'admin-role-requests.php',
                ];
            }

            // TODO: Add permission_requests count when that feature is implemented
            // $permissionRequestRepo = new PermissionRequestRepository();
            // $notifications['pending_permission_requests'] = $permissionRequestRepo->getPendingCount();

            $notifications['total'] = $notifications['pending_role_requests']
                                    + $notifications['pending_permission_requests'];
        }

        // Add Cache-Control header to prevent caching
        $response = $response->withHeader('Cache-Control', 'no-store');

        return $this->encodeResponseBody($response, $notifications);
    }
}
