<?php

namespace LiturgicalCalendar\Api\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;
use UnexpectedValueException;
use DomainException;

/**
 * JWT Service for generating and verifying JSON Web Tokens
 *
 * This service handles:
 * - JWT token generation for authenticated users
 * - JWT token verification
 * - Refresh token generation
 * - Token payload extraction
 *
 * @package LiturgicalCalendar\Api\Services
 */
class JwtService
{
    private string $secret;
    private string $algorithm;
    private int $expiry;
    private int $refreshExpiry;

    /**
     * Constructor
     *
     * @param string $secret        Secret key for signing tokens (minimum 32 characters recommended)
     * @param string $algorithm     Algorithm to use (default: HS256)
     * @param int    $expiry        Access token expiry in seconds (default: 3600 = 1 hour)
     * @param int    $refreshExpiry Refresh token expiry in seconds (default: 604800 = 7 days)
     */
    public function __construct(
        string $secret,
        string $algorithm = 'HS256',
        int $expiry = 3600,
        int $refreshExpiry = 604800
    ) {
        if (strlen($secret) < 32) {
            throw new DomainException('JWT secret must be at least 32 characters long');
        }

        $this->secret        = $secret;
        $this->algorithm     = $algorithm;
        $this->expiry        = $expiry;
        $this->refreshExpiry = $refreshExpiry;
    }

    /**
     * Generate an access token
     *
     * @param string                $username  Username/identifier for the token subject
     * @param array<string, mixed>  $claims    Additional claims to include in the token
     * @return string                          JWT token
     */
    public function generate(string $username, array $claims = []): string
    {
        $issuedAt  = time();
        $expiresAt = $issuedAt + $this->expiry;

        $payload = array_merge([
            'iss'  => $_SERVER['HTTP_HOST'] ?? 'liturgicalcalendar.org',  // Issuer
            'aud'  => $_SERVER['HTTP_HOST'] ?? 'liturgicalcalendar.org',  // Audience
            'iat'  => $issuedAt,                                           // Issued at
            'exp'  => $expiresAt,                                          // Expires at
            'sub'  => $username,                                           // Subject (username)
            'type' => 'access'                                            // Token type
        ], $claims);

        return JWT::encode($payload, $this->secret, $this->algorithm);
    }

    /**
     * Generate a refresh token
     *
     * @param string $username  Username/identifier for the token subject
     * @return string           JWT refresh token
     */
    public function generateRefreshToken(string $username): string
    {
        $issuedAt  = time();
        $expiresAt = $issuedAt + $this->refreshExpiry;

        $payload = [
            'iss'  => $_SERVER['HTTP_HOST'] ?? 'liturgicalcalendar.org',
            'aud'  => $_SERVER['HTTP_HOST'] ?? 'liturgicalcalendar.org',
            'iat'  => $issuedAt,
            'exp'  => $expiresAt,
            'sub'  => $username,
            'type' => 'refresh'
        ];

        return JWT::encode($payload, $this->secret, $this->algorithm);
    }

    /**
     * Verify a JWT token and return its payload
     *
     * @param string $token JWT token to verify
     * @return object|null  Decoded token payload, or null if invalid
     */
    public function verify(string $token): ?object
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algorithm));

            // Verify token type is 'access'
            if (!isset($decoded->type) || $decoded->type !== 'access') {
                return null;
            }

            return $decoded;
        } catch (ExpiredException $e) {
            // Token has expired
            return null;
        } catch (SignatureInvalidException $e) {
            // Token signature is invalid
            return null;
        } catch (BeforeValidException $e) {
            // Token not yet valid
            return null;
        } catch (UnexpectedValueException $e) {
            // Token malformed or other error
            return null;
        }
    }

    /**
     * Verify a refresh token and return its payload
     *
     * @param string $token Refresh token to verify
     * @return object|null  Decoded token payload, or null if invalid
     */
    public function verifyRefreshToken(string $token): ?object
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algorithm));

            // Verify token type is 'refresh'
            if (!isset($decoded->type) || $decoded->type !== 'refresh') {
                return null;
            }

            return $decoded;
        } catch (ExpiredException $e) {
            return null;
        } catch (SignatureInvalidException $e) {
            return null;
        } catch (BeforeValidException $e) {
            return null;
        } catch (UnexpectedValueException $e) {
            return null;
        }
    }

    /**
     * Refresh an access token using a refresh token
     *
     * @param string $refreshToken Refresh token
     * @return string|null         New access token, or null if refresh token is invalid
     */
    public function refresh(string $refreshToken): ?string
    {
        $payload = $this->verifyRefreshToken($refreshToken);

        if ($payload === null || !isset($payload->sub) || !is_string($payload->sub)) {
            return null;
        }

        $username = $payload->sub;

        // Extract custom claims from the refresh token payload
        // Exclude standard JWT claims that will be regenerated
        $standardClaims = ['iss', 'aud', 'iat', 'exp', 'nbf', 'jti', 'type', 'sub'];
        $customClaims   = [];
        foreach (get_object_vars($payload) as $key => $value) {
            if (!in_array($key, $standardClaims, true)) {
                $customClaims[$key] = $value;
            }
        }

        // Generate new access token with the same subject and custom claims
        return $this->generate($username, $customClaims);
    }

    /**
     * Extract username from token without verification
     * (Use only for debugging, always verify tokens in production)
     *
     * @param string $token JWT token
     * @return string|null  Username/subject, or null if token is malformed
     */
    public function extractUsername(string $token): ?string
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }

            // Convert base64url to standard base64
            // JWT uses base64url encoding: replace '-' with '+', '_' with '/'
            $base64 = strtr($parts[1], '-_', '+/');
            // Add padding to make length a multiple of 4
            $remainder = strlen($base64) % 4;
            if ($remainder > 0) {
                $base64 .= str_repeat('=', 4 - $remainder);
            }

            $payload = json_decode(base64_decode($base64));
            if (!is_object($payload) || !isset($payload->sub) || !is_string($payload->sub)) {
                return null;
            }
            return $payload->sub;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the token expiry time in seconds
     *
     * @return int Token expiry in seconds
     */
    public function getExpiry(): int
    {
        return $this->expiry;
    }

    /**
     * Get the refresh token expiry time in seconds
     *
     * @return int Refresh token expiry in seconds
     */
    public function getRefreshExpiry(): int
    {
        return $this->refreshExpiry;
    }
}
