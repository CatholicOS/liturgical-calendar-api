<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

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

    /**
     * Create Zitadel service.
     *
     * @param string $issuer Zitadel issuer URL
     * @param string $projectId Zitadel project ID
     * @param string|null $machineToken Service account token for management API
     */
    public function __construct(
        string $issuer,
        string $projectId,
        ?string $machineToken = null
    ) {
        $this->issuer       = rtrim($issuer, '/');
        $this->projectId    = $projectId;
        $this->machineToken = $machineToken;
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
     * @return array|null User data or null if not found
     */
    public function getUser(string $userId): ?array
    {
        try {
            $response = $this->httpClient->get("/management/v1/users/{$userId}", [
                'headers' => $this->getAuthHeaders(),
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return $data['user'] ?? null;
        } catch (GuzzleException $e) {
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

            $data   = json_decode($response->getBody()->getContents(), true);
            $grants = $data['result'] ?? [];

            $roles = [];
            foreach ($grants as $grant) {
                if (isset($grant['roleKeys']) && is_array($grant['roleKeys'])) {
                    $roles = array_merge($roles, $grant['roleKeys']);
                }
            }

            return array_unique($roles);
        } catch (GuzzleException $e) {
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
            return false;
        }
    }

    /**
     * Search for users by email.
     *
     * @param string $email Email to search for
     * @return array<array> List of matching users
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
            return $data['result'] ?? [];
        } catch (GuzzleException $e) {
            return [];
        }
    }

    /**
     * Get OIDC discovery document.
     *
     * @return array|null Discovery document or null on error
     */
    public function getDiscoveryDocument(): ?array
    {
        try {
            $response = $this->httpClient->get('/.well-known/openid-configuration');
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
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
        return $doc['authorization_endpoint'] ?? null;
    }

    /**
     * Get token endpoint URL.
     *
     * @return string|null Token endpoint or null
     */
    public function getTokenEndpoint(): ?string
    {
        $doc = $this->getDiscoveryDocument();
        return $doc['token_endpoint'] ?? null;
    }

    /**
     * Get userinfo endpoint URL.
     *
     * @return string|null Userinfo endpoint or null
     */
    public function getUserinfoEndpoint(): ?string
    {
        $doc = $this->getDiscoveryDocument();
        return $doc['userinfo_endpoint'] ?? null;
    }

    /**
     * Get end session endpoint URL.
     *
     * @return string|null End session endpoint or null
     */
    public function getEndSessionEndpoint(): ?string
    {
        $doc = $this->getDiscoveryDocument();
        return $doc['end_session_endpoint'] ?? null;
    }

    /**
     * Get JWKS URI.
     *
     * @return string|null JWKS URI or null
     */
    public function getJwksUri(): ?string
    {
        $doc = $this->getDiscoveryDocument();
        return $doc['jwks_uri'] ?? null;
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
