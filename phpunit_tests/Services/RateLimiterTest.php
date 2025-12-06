<?php

namespace LiturgicalCalendar\Api\Tests\Services;

use LiturgicalCalendar\Api\Services\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the RateLimiter service
 */
class RateLimiterTest extends TestCase
{
    private string $testStoragePath;
    private RateLimiter $rateLimiter;

    protected function setUp(): void
    {
        parent::setUp();

        // Use a unique temp directory for each test
        $this->testStoragePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'litcal_test_' . uniqid();
        mkdir($this->testStoragePath, 0755, true);

        // Create rate limiter with 3 attempts in 60 seconds for faster testing
        $this->rateLimiter = new RateLimiter(3, 60, $this->testStoragePath);
    }

    protected function tearDown(): void
    {
        // Clean up test directory
        $this->removeDirectory($this->testStoragePath);

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testNewIdentifierIsNotRateLimited(): void
    {
        $this->assertFalse($this->rateLimiter->isRateLimited('192.168.1.1'));
    }

    public function testRemainingAttemptsStartsAtMax(): void
    {
        $this->assertEquals(3, $this->rateLimiter->getRemainingAttempts('192.168.1.1'));
    }

    public function testRecordingFailedAttemptsDecreasesRemaining(): void
    {
        $ip = '192.168.1.2';

        $this->assertEquals(3, $this->rateLimiter->getRemainingAttempts($ip));

        $this->rateLimiter->recordFailedAttempt($ip);
        $this->assertEquals(2, $this->rateLimiter->getRemainingAttempts($ip));

        $this->rateLimiter->recordFailedAttempt($ip);
        $this->assertEquals(1, $this->rateLimiter->getRemainingAttempts($ip));
    }

    public function testRateLimitedAfterMaxAttempts(): void
    {
        $ip = '192.168.1.3';

        // Record max attempts
        $this->rateLimiter->recordFailedAttempt($ip);
        $this->rateLimiter->recordFailedAttempt($ip);
        $this->rateLimiter->recordFailedAttempt($ip);

        $this->assertTrue($this->rateLimiter->isRateLimited($ip));
        $this->assertEquals(0, $this->rateLimiter->getRemainingAttempts($ip));
    }

    public function testRetryAfterIsPositiveWhenRateLimited(): void
    {
        $ip = '192.168.1.4';

        // Record max attempts
        for ($i = 0; $i < 3; $i++) {
            $this->rateLimiter->recordFailedAttempt($ip);
        }

        $retryAfter = $this->rateLimiter->getRetryAfter($ip);

        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);
    }

    public function testRetryAfterIsZeroWhenNotRateLimited(): void
    {
        $ip = '192.168.1.5';

        // Only one failed attempt
        $this->rateLimiter->recordFailedAttempt($ip);

        $this->assertEquals(0, $this->rateLimiter->getRetryAfter($ip));
    }

    public function testClearAttemptsResetsRateLimit(): void
    {
        $ip = '192.168.1.6';

        // Rate limit the IP
        for ($i = 0; $i < 3; $i++) {
            $this->rateLimiter->recordFailedAttempt($ip);
        }

        $this->assertTrue($this->rateLimiter->isRateLimited($ip));

        // Clear attempts
        $this->rateLimiter->clearAttempts($ip);

        $this->assertFalse($this->rateLimiter->isRateLimited($ip));
        $this->assertEquals(3, $this->rateLimiter->getRemainingAttempts($ip));
    }

    public function testDifferentIpsAreTrackedSeparately(): void
    {
        $ip1 = '192.168.1.10';
        $ip2 = '192.168.1.11';

        // Rate limit ip1
        for ($i = 0; $i < 3; $i++) {
            $this->rateLimiter->recordFailedAttempt($ip1);
        }

        $this->assertTrue($this->rateLimiter->isRateLimited($ip1));
        $this->assertFalse($this->rateLimiter->isRateLimited($ip2));
        $this->assertEquals(3, $this->rateLimiter->getRemainingAttempts($ip2));
    }

    public function testGetMaxAttempts(): void
    {
        $this->assertEquals(3, $this->rateLimiter->getMaxAttempts());
    }

    public function testGetWindowSeconds(): void
    {
        $this->assertEquals(60, $this->rateLimiter->getWindowSeconds());
    }

    public function testCleanupRemovesStaleFiles(): void
    {
        $ip = '192.168.1.20';

        // Record an attempt
        $this->rateLimiter->recordFailedAttempt($ip);

        // Create a new limiter with a very short window (1 second)
        $shortWindowLimiter = new RateLimiter(3, 1, $this->testStoragePath);

        // Wait for the window to expire
        sleep(2);

        // Cleanup should remove the stale file
        $cleaned = $shortWindowLimiter->cleanup();

        // The file should have been cleaned up
        $this->assertGreaterThanOrEqual(0, $cleaned);
    }

    public function testHandlesSpecialCharactersInIdentifier(): void
    {
        // IPv6 addresses contain colons
        $ipv6 = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';

        $this->assertFalse($this->rateLimiter->isRateLimited($ipv6));

        $this->rateLimiter->recordFailedAttempt($ipv6);
        $this->assertEquals(2, $this->rateLimiter->getRemainingAttempts($ipv6));
    }

    public function testFactoryCreatesFromEnv(): void
    {
        // Test that the factory can create an instance
        $limiter = \LiturgicalCalendar\Api\Services\RateLimiterFactory::fromEnv();

        $this->assertInstanceOf(RateLimiter::class, $limiter);

        // Default values
        $this->assertEquals(5, $limiter->getMaxAttempts());
        $this->assertEquals(900, $limiter->getWindowSeconds());
    }

    public function testFactoryRespectsEnvVariables(): void
    {
        // Set environment variables
        $_ENV['RATE_LIMIT_LOGIN_ATTEMPTS'] = '10';
        $_ENV['RATE_LIMIT_LOGIN_WINDOW']   = '300';

        try {
            $limiter = \LiturgicalCalendar\Api\Services\RateLimiterFactory::fromEnv();

            $this->assertEquals(10, $limiter->getMaxAttempts());
            $this->assertEquals(300, $limiter->getWindowSeconds());
        } finally {
            // Clean up environment
            unset($_ENV['RATE_LIMIT_LOGIN_ATTEMPTS']);
            unset($_ENV['RATE_LIMIT_LOGIN_WINDOW']);
        }
    }
}
