<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Service for interacting with Zitadel Management API.
 *
 * Used for administrative operations such as:
 * - Fetching user details
 * - Managing roles
 * - Creating service accounts
 */
class ZitadelService
{
    private Client $httpClient;
    private string $issuer;
    private string $projectId;
    private ?string $machineToken;
    private ?LoggerInterface $logger;

    /**
     * Create Zitadel service.
     *
     * @param string $issuer Zitadel issuer URL
     * @param string $projectId Zitadel project ID
     * @param string|null $machineToken Service account token for management API
     * @param LoggerInterface|null $logger Optional logger for error logging
     */
    public function __construct(
        string $issuer,
        string $projectId,
        ?string $machineToken = null,
        ?LoggerInterface $logger = null
    ) {
        $this->issuer       = rtrim($issuer, '/');
        $this->projectId    = $projectId;
        $this->machineToken = $machineToken;
        $this->logger       = $logger;
        $this->httpClient   = new Client([
            'base_uri' => $this->issuer,
            'timeout'  => 30,
        ]);
    }

    /**
     * Create service from environment variables.
     *
     * @return self
     * @throws \RuntimeException If required environment variables are missing
     */
    public static function fromEnv(): self
    {
        $issuer       = getenv('ZITADEL_ISSUER');
        $projectId    = getenv('ZITADEL_PROJECT_ID');
        $machineToken = getenv('ZITADEL_MACHINE_TOKEN') ?: null;

        if ($issuer === false || $projectId === false) {
            throw new \RuntimeException(
                'Missing required environment variables: ZITADEL_ISSUER, ZITADEL_PROJECT_ID'
            );
        }

        return new self($issuer, $projectId, $machineToken);
    }

    /**
     * Get user information by ID.
     *
     * @param string $userId Zitadel user ID
     * @return array<string, mixed>|null User data or null if not found
     */
    public function getUser(string $userId): ?array
    {
        try {
            $response = $this->httpClient->get("/management/v1/users/{$userId}", [
                'headers' => $this->getAuthHeaders(),
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (!is_array($data)) {
                return null;
            }
            $user = $data['user'] ?? null;
            /** @var array<string, mixed>|null */
            return is_array($user) ? $user : null;
        } catch (GuzzleException $e) {
            $this->logger?->warning('Failed to fetch user from Zitadel', [
                'userId' => $userId,
                'error'  => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get user roles for the project.
     *
     * @param string $userId Zitadel user ID
     * @return array<string> List of role names
     */
    public function getUserRoles(string $userId): array
    {
        try {
            $response = $this->httpClient->post('/management/v1/users/grants/_search', [
                'headers' => $this->getAuthHeaders(),
                'json'    => [
                    'queries' => [
                        [
                            'userIdQuery' => ['userId' => $userId],
                        ],
                        [
                            'projectIdQuery' => [
                                'projectId' => $this->projectId,
                            ],
                        ],
                    ],
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (!is_array($data)) {
                return [];
            }
            $grants = $data['result'] ?? [];

            if (!is_array($grants)) {
                return [];
            }

            /** @var array<string> $roles */
            $roles = [];
            foreach ($grants as $grant) {
                if (is_array($grant) && isset($grant['roleKeys']) && is_array($grant['roleKeys'])) {
                    foreach ($grant['roleKeys'] as $roleKey) {
                        if (is_string($roleKey)) {
                            $roles[] = $roleKey;
                        }
                    }
                }
            }

            return array_values(array_unique($roles));
        } catch (GuzzleException $e) {
            $this->logger?->warning('Failed to fetch user roles from Zitadel', [
                'userId' => $userId,
                'error'  => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Grant a role to a user.
     *
     * @param string $userId Zitadel user ID
     * @param string $role Role name to grant
     * @return bool True if successful
     */
    public function grantRole(string $userId, string $role): bool
    {
        try {
            $this->httpClient->post("/management/v1/users/{$userId}/grants", [
                'headers' => $this->getAuthHeaders(),
                'json'    => [
                    'projectId' => $this->projectId,
                    'roleKeys'  => [$role],
                ],
            ]);

            return true;
        } catch (GuzzleException $e) {
            $this->logger?->warning('Failed to grant role in Zitadel', [
                'userId' => $userId,
                'role'   => $role,
                'error'  => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Revoke a role from a user.
     *
     * @param string $userId Zitadel user ID
     * @param string $grantId User grant ID
     * @return bool True if successful
     */
    public function revokeGrant(string $userId, string $grantId): bool
    {
        try {
            $this->httpClient->delete("/management/v1/users/{$userId}/grants/{$grantId}", [
                'headers' => $this->getAuthHeaders(),
            ]);

            return true;
        } catch (GuzzleException $e) {
            $this->logger?->warning('Failed to revoke grant in Zitadel', [
                'userId'  => $userId,
                'grantId' => $grantId,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Search for users by email.
     *
     * @param string $email Email to search for
     * @return array<int, array<string, mixed>> List of matching users
     */
    public function searchUsersByEmail(string $email): array
    {
        try {
            $response = $this->httpClient->post('/management/v1/users/_search', [
                'headers' => $this->getAuthHeaders(),
                'json'    => [
                    'queries' => [
                        [
                            'emailQuery' => [
                                'emailAddress' => $email,
                                'method'       => 'TEXT_QUERY_METHOD_EQUALS',
                            ],
                        ],
                    ],
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (!is_array($data)) {
                return [];
            }
            $result = $data['result'] ?? [];
            /** @var array<int, array<string, mixed>> */
            return is_array($result) ? $result : [];
        } catch (GuzzleException $e) {
            $this->logger?->warning('Failed to search users by email in Zitadel', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get OIDC discovery document.
     *
     * @return array<string, mixed>|null Discovery document or null on error
     */
    public function getDiscoveryDocument(): ?array
    {
        try {
            $response = $this->httpClient->get('/.well-known/openid-configuration');
            $data     = json_decode($response->getBody()->getContents(), true);
            /** @var array<string, mixed>|null */
            return is_array($data) ? $data : null;
        } catch (GuzzleException $e) {
            $this->logger?->warning('Failed to fetch OIDC discovery document from Zitadel', [
                'issuer' => $this->issuer,
                'error'  => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get authorization endpoint URL.
     *
     * @return string|null Authorization endpoint or null
     */
    public function getAuthorizationEndpoint(): ?string
    {
        $doc = $this->getDiscoveryDocument();
        if ($doc === null) {
            return null;
        }
        $endpoint = $doc['authorization_endpoint'] ?? null;
        return is_string($endpoint) ? $endpoint : null;
    }

    /**
     * Get token endpoint URL.
     *
     * @return string|null Token endpoint or null
     */
    public function getTokenEndpoint(): ?string
    {
        $doc = $this->getDiscoveryDocument();
        if ($doc === null) {
            return null;
        }
        $endpoint = $doc['token_endpoint'] ?? null;
        return is_string($endpoint) ? $endpoint : null;
    }

    /**
     * Get userinfo endpoint URL.
     *
     * @return string|null Userinfo endpoint or null
     */
    public function getUserinfoEndpoint(): ?string
    {
        $doc = $this->getDiscoveryDocument();
        if ($doc === null) {
            return null;
        }
        $endpoint = $doc['userinfo_endpoint'] ?? null;
        return is_string($endpoint) ? $endpoint : null;
    }

    /**
     * Get end session endpoint URL.
     *
     * @return string|null End session endpoint or null
     */
    public function getEndSessionEndpoint(): ?string
    {
        $doc = $this->getDiscoveryDocument();
        if ($doc === null) {
            return null;
        }
        $endpoint = $doc['end_session_endpoint'] ?? null;
        return is_string($endpoint) ? $endpoint : null;
    }

    /**
     * Get JWKS URI.
     *
     * @return string|null JWKS URI or null
     */
    public function getJwksUri(): ?string
    {
        $doc = $this->getDiscoveryDocument();
        if ($doc === null) {
            return null;
        }
        $uri = $doc['jwks_uri'] ?? null;
        return is_string($uri) ? $uri : null;
    }

    /**
     * Check if user has a specific role.
     *
     * @param string $userId Zitadel user ID
     * @param string $role Role to check
     * @return bool True if user has the role
     */
    public function userHasRole(string $userId, string $role): bool
    {
        $roles = $this->getUserRoles($userId);
        return in_array($role, $roles, true);
    }

    /**
     * Get the issuer URL.
     *
     * @return string Issuer URL
     */
    public function getIssuer(): string
    {
        return $this->issuer;
    }

    /**
     * Get the project ID.
     *
     * @return string Project ID
     */
    public function getProjectId(): string
    {
        return $this->projectId;
    }

    /**
     * Get authorization headers for Management API.
     *
     * @return array<string, string> Headers
     */
    private function getAuthHeaders(): array
    {
        $headers = [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if ($this->machineToken !== null) {
            $headers['Authorization'] = 'Bearer ' . $this->machineToken;
        }

        return $headers;
    }
}
