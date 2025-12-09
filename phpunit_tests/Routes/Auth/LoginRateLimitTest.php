<?php

namespace LiturgicalCalendar\Tests\Routes\Auth;

use LiturgicalCalendar\Tests\ApiTestCase;

/**
 * Integration tests for login endpoint rate limiting
 *
 * These tests verify that the /auth/login endpoint properly enforces rate limiting
 * to protect against brute-force attacks.
 *
 * Note: These tests are marked @group slow because they make multiple HTTP requests
 * and may take longer to complete.
 *
 * @group slow
 */
class LoginRateLimitTest extends ApiTestCase
{
    /**
     * Clear rate limit state before each test.
     *
     * This ensures that rate limit state doesn't carry over from previous tests.
     * We attempt to clear files directly first (works when test process shares
     * filesystem with API), then fall back to successful login approach for
     * environments where the API runs in a container with different filesystem.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Try to clear rate limit files directly (works in local dev)
        $this->clearRateLimitFiles();

        // Also attempt a successful login as a fallback for containerized environments
        // where we may not have filesystem access to the API's rate limit storage
        self::$http->post('/auth/login', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json'
            ],
            'json'    => [
                'username' => $_ENV['ADMIN_USERNAME'] ?? 'admin',
                'password' => $_ENV['ADMIN_PASSWORD'] ?? 'password'
            ]
        ]);
    }

    /**
     * Clear all rate limit files from the storage directory.
     *
     * This is a best-effort operation that silently fails if the directory
     * doesn't exist or files can't be deleted (e.g., in containerized CI
     * environments where the API has a different filesystem).
     */
    private function clearRateLimitFiles(): void
    {
        // Get the storage path (default is system temp dir)
        $storagePath  = $_ENV['RATE_LIMIT_STORAGE_PATH'] ?? sys_get_temp_dir();
        $rateLimitDir = rtrim($storagePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'litcal_rate_limits';

        if (!is_dir($rateLimitDir)) {
            return;
        }

        $files = glob($rateLimitDir . DIRECTORY_SEPARATOR . '*.json');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            @unlink($file);
        }
    }

    /**
     * Exhaust the rate limit by making failed login attempts up to the configured maximum.
     *
     * @return \Psr\Http\Message\ResponseInterface The rate-limited (429) response after exceeding the limit.
     */
    private function exhaustRateLimit(): \Psr\Http\Message\ResponseInterface
    {
        $maxAttempts = (int) ( $_ENV['RATE_LIMIT_LOGIN_ATTEMPTS'] ?? 5 );

        for ($i = 0; $i < $maxAttempts; $i++) {
            self::$http->post('/auth/login', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json'
                ],
                'json'    => [
                    'username' => 'admin',
                    'password' => 'wrong-' . uniqid()
                ]
            ]);
        }

        // Return the rate-limited response
        return self::$http->post('/auth/login', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json'
            ],
            'json'    => [
                'username' => 'admin',
                'password' => 'wrong-final'
            ]
        ]);
    }

    /**
     * Test that login fails with invalid credentials.
     *
     * This is a basic test to ensure the login endpoint returns 401 for wrong passwords.
     */
    public function testLoginFailsWithInvalidCredentials(): void
    {
        $response = self::$http->post('/auth/login', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json'
            ],
            'json'    => [
                'username' => 'admin',
                'password' => 'wrong-password-' . uniqid()
            ]
        ]);

        $this->assertEquals(401, $response->getStatusCode());
    }

    /**
     * Test that rate limiting is triggered after multiple failed login attempts.
     *
     * The API is configured with RATE_LIMIT_LOGIN_ATTEMPTS (default: 5) and
     * RATE_LIMIT_LOGIN_WINDOW (default: 900 seconds). This test makes more
     * failed attempts than the limit to trigger rate limiting.
     *
     * Important: This test uses a unique "identifier" to avoid affecting other tests.
     * Since rate limiting is IP-based, we rely on the test environment configuration.
     */
    public function testRateLimitingTriggeredAfterMaxAttempts(): void
    {
        // Get the configured rate limit (default is 5)
        $maxAttempts = (int) ( $_ENV['RATE_LIMIT_LOGIN_ATTEMPTS'] ?? 5 );

        // Make failed login attempts up to the limit
        for ($i = 0; $i < $maxAttempts; $i++) {
            $response = self::$http->post('/auth/login', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json'
                ],
                'json'    => [
                    'username' => 'admin',
                    'password' => 'wrong-password-attempt-' . $i
                ]
            ]);

            // Each failed attempt should return 401 until we hit the limit
            $this->assertEquals(
                401,
                $response->getStatusCode(),
                'Expected 401 on attempt ' . ( $i + 1 ) . ' of ' . $maxAttempts
            );
        }

        // The next attempt should be rate limited (429)
        $response = self::$http->post('/auth/login', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json'
            ],
            'json'    => [
                'username' => 'admin',
                'password' => 'wrong-password-final'
            ]
        ]);

        $this->assertEquals(
            429,
            $response->getStatusCode(),
            'Expected 429 Too Many Requests after ' . $maxAttempts . ' failed attempts'
        );

        // Verify Retry-After header is present
        $this->assertTrue(
            $response->hasHeader('Retry-After'),
            'Expected Retry-After header in 429 response'
        );

        $retryAfter = $response->getHeaderLine('Retry-After');
        $this->assertGreaterThan(0, (int) $retryAfter, 'Retry-After should be a positive integer');
    }

    /**
     * Test that the 429 response body contains expected error details.
     *
     * The response should follow RFC 7807 Problem Details format.
     */
    public function testRateLimitResponseFormat(): void
    {
        $response = $this->exhaustRateLimit();

        $this->assertEquals(429, $response->getStatusCode());

        // Parse response body
        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        $this->assertIsArray($data, 'Response body should be valid JSON');

        // Check RFC 7807 Problem Details fields
        $this->assertArrayHasKey('status', $data, 'Response should have status field');
        $this->assertEquals(429, $data['status']);

        $this->assertArrayHasKey('title', $data, 'Response should have title field');
        $this->assertArrayHasKey('detail', $data, 'Response should have detail field');

        // Check for retryAfter in body (custom field)
        $this->assertArrayHasKey('retryAfter', $data, 'Response should have retryAfter field');
        $this->assertIsInt($data['retryAfter']);
        $this->assertGreaterThan(0, $data['retryAfter']);
    }

    /**
     * Test that successful login clears the rate limit.
     *
     * After a user successfully authenticates, their rate limit counter should
     * be cleared, allowing subsequent attempts.
     */
    public function testSuccessfulLoginClearsRateLimit(): void
    {
        // Get the configured rate limit (default is 5)
        $maxAttempts = (int) ( $_ENV['RATE_LIMIT_LOGIN_ATTEMPTS'] ?? 5 );

        // Make some failed attempts (but not enough to trigger rate limiting)
        $failedAttempts = max(1, $maxAttempts - 2);
        for ($i = 0; $i < $failedAttempts; $i++) {
            $response = self::$http->post('/auth/login', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json'
                ],
                'json'    => [
                    'username' => 'admin',
                    'password' => 'wrong-password-clear-test-' . $i
                ]
            ]);
            $this->assertEquals(401, $response->getStatusCode());
        }

        // Now login successfully
        $response = self::$http->post('/auth/login', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json'
            ],
            'json'    => [
                'username' => $_ENV['ADMIN_USERNAME'] ?? 'admin',
                'password' => $_ENV['ADMIN_PASSWORD'] ?? 'password'
            ]
        ]);

        $this->assertEquals(
            200,
            $response->getStatusCode(),
            'Successful login should return 200'
        );

        // Now we should be able to make failed attempts again without being rate limited
        // Make the same number of failed attempts as before
        for ($i = 0; $i < $failedAttempts; $i++) {
            $response = self::$http->post('/auth/login', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json'
                ],
                'json'    => [
                    'username' => 'admin',
                    'password' => 'wrong-password-after-clear-' . $i
                ]
            ]);

            // Should get 401 (not 429) because rate limit was cleared
            $this->assertEquals(
                401,
                $response->getStatusCode(),
                'Expected 401 (not 429) on attempt ' . ( $i + 1 ) . ' after rate limit cleared'
            );
        }
    }

    /**
     * Test that rate limiting returns proper Content-Type header.
     */
    public function testRateLimitResponseContentType(): void
    {
        $response = $this->exhaustRateLimit();

        $this->assertEquals(429, $response->getStatusCode());

        // Check Content-Type header - should be application/problem+json for RFC 7807 compliance
        $contentType = $response->getHeaderLine('Content-Type');
        $this->assertStringContainsString(
            'application/problem+json',
            $contentType,
            'Rate limit response should use application/problem+json content type'
        );
    }

    /**
     * Clean up rate limit data after each test.
     *
     * This ensures that rate limit state doesn't carry over to other test classes.
     * We clear files directly first (essential when tests end in rate-limited state),
     * then attempt a successful login as a fallback for containerized environments.
     */
    protected function tearDown(): void
    {
        // Clear rate limit files directly first (essential when rate-limited)
        $this->clearRateLimitFiles();

        // Also attempt a successful login as a fallback for containerized environments
        self::$http->post('/auth/login', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json'
            ],
            'json'    => [
                'username' => $_ENV['ADMIN_USERNAME'] ?? 'admin',
                'password' => $_ENV['ADMIN_PASSWORD'] ?? 'password'
            ]
        ]);

        parent::tearDown();
    }
}
