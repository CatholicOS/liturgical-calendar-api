# Production Security Guide

This document covers the security features and configuration required for deploying the Liturgical Calendar API in production environments.

## Overview

The API includes several security mechanisms to protect authentication endpoints:

1. **Rate Limiting** - Brute-force protection for login attempts
2. **HTTPS Enforcement** - Requires secure connections in production
3. **JWT Secret Validation** - Prevents deployment with placeholder secrets
4. **HttpOnly Cookies** - Secure token storage

## Rate Limiting

### How It Works

The API implements IP-based rate limiting on the `/auth/login` endpoint to prevent brute-force attacks:

- Failed login attempts are tracked per IP address
- After exceeding the limit, further attempts are blocked with HTTP 429
- The `Retry-After` header indicates when the client can try again
- Successful login clears the rate limit for that IP

### Configuration

```env
# Maximum failed attempts before lockout (default: 5)
RATE_LIMIT_LOGIN_ATTEMPTS=5

# Time window in seconds (default: 900 = 15 minutes)
RATE_LIMIT_LOGIN_WINDOW=900

# Storage path for rate limit data (default: system temp directory)
# Use a persistent path in production for multi-process environments
RATE_LIMIT_STORAGE_PATH=/var/lib/litcal/rate_limits
```

### Response When Rate Limited

```http
HTTP/1.1 429 Too Many Requests
Content-Type: application/problem+json
Retry-After: 847

{
    "type": "https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/429",
    "title": "Too Many Requests",
    "status": 429,
    "detail": "Too many login attempts. Please try again later.",
    "retryAfter": 847
}
```

### Recommendations

- **Persistent storage**: Use a dedicated directory (not `/tmp`) that persists across deployments
- **Shared storage**: In load-balanced environments, use a shared filesystem or consider Redis
- **Monitoring**: Monitor auth logs for patterns of rate-limited IPs
- **Adjust limits**: Increase `RATE_LIMIT_LOGIN_WINDOW` for more aggressive protection

## HTTPS Enforcement

### How It Works

In `staging` and `production` environments (determined by `APP_ENV`), the API:

- Requires HTTPS for all authentication endpoints (`/auth/*`)
- Returns HTTP 403 Forbidden for non-HTTPS requests
- Can be disabled for special cases (e.g., internal load balancers)

### Configuration

```env
# Environment must be staging or production for enforcement
APP_ENV=production

# Enable/disable HTTPS enforcement (default: true in staging/production)
HTTPS_ENFORCEMENT=true
```

### HTTPS Detection

The API detects HTTPS connections via:

1. `$_SERVER['REQUEST_SCHEME'] === 'https'`
2. `$_SERVER['HTTPS'] === 'on'`
3. `$_SERVER['SERVER_PORT'] === '443'`
4. `$_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'`

### Reverse Proxy Configuration

When TLS terminates at a reverse proxy, configure it to set the `X-Forwarded-Proto` header.

#### Nginx

```nginx
server {
    listen 443 ssl;
    server_name api.example.com;

    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    location / {
        proxy_pass http://localhost:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}

# Redirect HTTP to HTTPS
server {
    listen 80;
    server_name api.example.com;
    return 301 https://$server_name$request_uri;
}
```

#### Apache

```apache
<VirtualHost *:443>
    ServerName api.example.com

    SSLEngine on
    SSLCertificateFile /path/to/cert.pem
    SSLCertificateKeyFile /path/to/key.pem

    ProxyPass / http://localhost:8000/
    ProxyPassReverse / http://localhost:8000/

    RequestHeader set X-Forwarded-Proto "https"
    RequestHeader set X-Forwarded-Port "443"
</VirtualHost>

<VirtualHost *:80>
    ServerName api.example.com
    Redirect permanent / https://api.example.com/
</VirtualHost>
```

### Disabling HTTPS Enforcement

In some cases (e.g., internal networks, testing), you may need to disable HTTPS enforcement:

```env
HTTPS_ENFORCEMENT=false
```

**Warning**: Only disable HTTPS enforcement if you understand the security implications.

## JWT Secret Security

### Requirements

The JWT secret must:

- Be at least 32 characters long
- Not contain placeholder patterns (in staging/production)
- Be unique to your deployment

### Generating a Secure Secret

```bash
# Generate a 64-character hexadecimal secret
php -r "echo bin2hex(random_bytes(32));"

# Or using OpenSSL
openssl rand -hex 32
```

### Placeholder Detection

In `staging` and `production` environments, the API rejects secrets containing common placeholder patterns:

- `change-this`, `change-me`, `replace-this`, `replace-me`
- `your-secret`, `my-secret`, `secret-key`
- `example`, `placeholder`, `default`, `insecure`
- `password`, `test-secret`, `dev-secret`
- `xxxxxxxx`

**Error when using a placeholder secret:**

```text
RuntimeException: JWT_SECRET appears to be a placeholder value. In staging/production
environments, you must use a secure random secret. Generate one with:
php -r "echo bin2hex(random_bytes(32));"
```

### Configuration

```env
# IMPORTANT: Generate a unique, random secret for production
# Do NOT use the example value below
JWT_SECRET=a1b2c3d4e5f6789012345678901234567890abcdef1234567890abcdef123456

# Algorithm (HS256, HS384, or HS512)
JWT_ALGORITHM=HS256

# Access token lifetime (default: 3600 = 1 hour)
JWT_EXPIRY=3600

# Refresh token lifetime (default: 604800 = 7 days)
JWT_REFRESH_EXPIRY=604800
```

## Cookie Security

### HttpOnly Cookies

Authentication tokens are stored in HttpOnly cookies that:

- Cannot be accessed by JavaScript (XSS protection)
- Are automatically sent with requests
- Include the `Secure` flag over HTTPS
- Use appropriate `SameSite` attributes

### Cookie Attributes

| Cookie                  | Path    | SameSite | Purpose                              |
|-------------------------|---------|----------|--------------------------------------|
| `litcal_access_token`   | `/`     | Lax      | Access token for API requests        |
| `litcal_refresh_token`  | `/auth` | Strict   | Refresh token (auth endpoints only)  |

### CORS Configuration

For cross-origin requests with cookies:

```env
# Specify allowed origins (required for credentialed requests)
CORS_ALLOWED_ORIGINS=https://frontend.example.com,https://admin.example.com
```

**Note**: Wildcard (`*`) is not recommended with cookie-based authentication.

## Admin Credentials

### Password Hash Generation

```bash
# Generate an Argon2id password hash
php -r "echo password_hash('your-secure-password', PASSWORD_ARGON2ID);"
```

### Configuration

```env
# Admin username (default: admin)
ADMIN_USERNAME=admin

# Argon2id password hash (REQUIRED in staging/production)
ADMIN_PASSWORD_HASH=$argon2id$v=19$m=65536,t=4,p=1$...
```

### Environment-Based Behavior

| Environment                | Behavior                                                              |
|----------------------------|-----------------------------------------------------------------------|
| `development` / `test`     | Falls back to default password "password" if hash is invalid/missing  |
| `staging` / `production`   | Requires valid `ADMIN_PASSWORD_HASH` (throws exception if missing)    |

## Security Checklist

Before deploying to production, ensure:

- [ ] `APP_ENV=production` is set
- [ ] `JWT_SECRET` is a unique, randomly generated 64+ character string
- [ ] `ADMIN_PASSWORD_HASH` is set with a strong Argon2id hash
- [ ] `CORS_ALLOWED_ORIGINS` lists specific origins (not `*`)
- [ ] HTTPS is configured (either directly or via reverse proxy)
- [ ] `X-Forwarded-Proto` header is set by reverse proxy (if applicable)
- [ ] Rate limit storage path is persistent and writable
- [ ] Authentication logs are monitored

## Monitoring

### Log Files

Authentication events are logged to:

- `logs/auth-YYYY-MM-DD.log` (plain text)
- `logs/auth.json-YYYY-MM-DD.log` (JSON format)

### Events Logged

- Successful logins (INFO)
- Failed login attempts (WARNING)
- Rate-limited requests (WARNING)
- Logouts (INFO)

### Example Log Entries

```text
[2025-12-06 10:30:00] INFO: Login successful
    username: admin
    client_ip: 192.168.1.100

[2025-12-06 10:35:00] WARNING: Login failed
    username: attacker
    client_ip: 192.168.1.50
    reason: Invalid credentials
    remaining_attempts: 2

[2025-12-06 10:40:00] WARNING: Login rate limited
    client_ip: 192.168.1.50
    retry_after: 600
```

## Incident Response

### Detecting Brute-Force Attacks

Signs of a brute-force attack:

- Multiple failed login attempts from the same IP
- Rate-limited requests appearing in logs
- Attempts from multiple IPs targeting the same account

### Response Steps

1. Review auth logs for attack patterns
2. Consider temporarily blocking offending IPs at firewall level
3. If an account may be compromised, rotate the `ADMIN_PASSWORD_HASH`
4. Rotate `JWT_SECRET` to invalidate all existing tokens
5. Review and potentially increase rate limit thresholds
