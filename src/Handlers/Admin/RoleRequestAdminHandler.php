<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Admin;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Middleware\OidcAuthMiddleware;
use LiturgicalCalendar\Api\Repositories\RoleRequestRepository;
use LiturgicalCalendar\Api\Services\ZitadelService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Role Request Admin Handler
 *
 * Handles admin operations for role requests:
 * - GET /admin/role-requests - List all pending requests
 * - POST /admin/role-requests/{id}/approve - Approve a request
 * - POST /admin/role-requests/{id}/reject - Reject a request
 *
 * Requires admin role.
 */
final class RoleRequestAdminHandler extends AbstractHandler
{
    private ?RoleRequestRepository $repository = null;

    public function __construct()
    {
        parent::__construct();

        $this->allowedRequestMethods = [RequestMethod::GET, RequestMethod::POST];
        $this->allowedAcceptHeaders  = [AcceptHeader::JSON];
        $this->allowCredentials      = true;
    }

    private function getRepository(): RoleRequestRepository
    {
        if ($this->repository === null) {
            $this->repository = new RoleRequestRepository();
        }
        return $this->repository;
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

        $adminId = $oidcUser['sub'] ?? '';
        if (empty($adminId)) {
            throw new UnauthorizedException('Invalid authentication token');
        }

        if (!Connection::isConfigured()) {
            throw new \RuntimeException('Database not configured');
        }

        // Parse path to determine action
        $path      = $request->getUri()->getPath();
        $pathParts = explode('/', trim($path, '/'));

        // Expected paths:
        // admin/role-requests
        // admin/role-requests/{id}/approve
        // admin/role-requests/{id}/reject

        if ($method === RequestMethod::GET) {
            return $this->listPendingRequests($response);
        }

        // POST requires an action (approve/reject)
        $partCount = count($pathParts);
        if ($partCount < 4) {
            throw new ValidationException('Invalid request path');
        }

        // Parse from the end to handle different route prefixes
        $action    = $pathParts[$partCount - 1];
        $requestId = $pathParts[$partCount - 2];

        // Validate UUID format
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $requestId)) {
            throw new ValidationException('Invalid request ID format');
        }

        $body  = $request->getParsedBody();
        $notes = null;
        if (is_array($body) && isset($body['notes']) && is_string($body['notes'])) {
            $notes = $body['notes'];
        }

        if ($action === 'approve') {
            return $this->approveRequest($response, $requestId, $adminId, $notes);
        }

        if ($action === 'reject') {
            return $this->rejectRequest($response, $requestId, $adminId, $notes);
        }

        throw new ValidationException('Invalid action. Use "approve" or "reject"');
    }

    /**
     * List all pending role requests.
     *
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    private function listPendingRequests(ResponseInterface $response): ResponseInterface
    {
        $repo     = $this->getRepository();
        $requests = $repo->getPendingRequests();
        $counts   = $repo->getRequestCounts();

        return $this->encodeResponseBody($response, [
            'pending_requests' => $requests,
            'counts'           => $counts,
        ]);
    }

    /**
     * Approve a role request.
     *
     * @param ResponseInterface $response
     * @param string $requestId Request UUID
     * @param string $adminId
     * @param string|null $notes
     * @return ResponseInterface
     */
    private function approveRequest(
        ResponseInterface $response,
        string $requestId,
        string $adminId,
        ?string $notes
    ): ResponseInterface {
        $repo = $this->getRepository();
        $db   = Connection::getInstance();

        $db->beginTransaction();

        try {
            // Approve in database
            $approvedRequest = $repo->approveRequest($requestId, $adminId, $notes);

            if ($approvedRequest === null) {
                $db->rollBack();
                throw new NotFoundException('Request not found or already processed');
            }

            // Assign role in Zitadel
            $userIdValue   = $approvedRequest['zitadel_user_id'] ?? null;
            $requestedRole = $approvedRequest['requested_role'] ?? null;
            $userId        = is_string($userIdValue) ? $userIdValue : '';
            $role          = is_string($requestedRole) ? $requestedRole : '';

            $roleAssigned = false;
            $zitadelError = null;

            if (ZitadelService::isConfigured() && !empty($userId) && !empty($role)) {
                try {
                    $zitadel = ZitadelService::fromEnv();
                    $zitadel->assignUserRole($userId, $role);
                    $roleAssigned = true;
                } catch (\Exception $e) {
                    // Rollback database changes if Zitadel assignment fails
                    $db->rollBack();
                    throw new \RuntimeException(
                        'Failed to assign role in Zitadel: ' . $e->getMessage(),
                        0,
                        $e
                    );
                }
            }

            $db->commit();

            return $this->encodeResponseBody($response, [
                'success'       => true,
                'request'       => $approvedRequest,
                'role_assigned' => $roleAssigned,
                'zitadel_error' => $zitadelError,
                'message'       => $roleAssigned
                    ? 'Request approved and role assigned in Zitadel'
                    : 'Request approved (Zitadel not configured)',
            ]);
        } catch (NotFoundException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Reject a role request.
     *
     * @param ResponseInterface $response
     * @param string $requestId Request UUID
     * @param string $adminId
     * @param string|null $notes
     * @return ResponseInterface
     */
    private function rejectRequest(
        ResponseInterface $response,
        string $requestId,
        string $adminId,
        ?string $notes
    ): ResponseInterface {
        $repo = $this->getRepository();

        $rejected = $repo->rejectRequest($requestId, $adminId, $notes);

        if (!$rejected) {
            throw new NotFoundException('Request not found or already processed');
        }

        return $this->encodeResponseBody($response, [
            'success' => true,
            'message' => 'Request rejected',
        ]);
    }
}
