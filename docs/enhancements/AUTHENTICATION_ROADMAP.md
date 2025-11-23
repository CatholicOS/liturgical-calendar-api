# Authentication & Authorization Roadmap

This document outlines the implementation plan for adding authentication, authorization, and API key management to the Liturgical Calendar API and Frontend.

## Overview

### Goals

1. **Developer API Access**
   - Developers register on the website
   - Register applications with name and purpose
   - Generate API keys for tracking and rate limiting
   - Track endpoint usage and collect statistics per application

2. **Protected Calendar Data Operations**
   - Authenticate users for PUT/PATCH/DELETE operations
   - Role-based access control (RBAC) for calendar data
   - Users can only modify calendars for which they have appropriate roles

### User Types

1. **API Consumers (Developers)**
   - Register account
   - Create and manage applications
   - Generate and rotate API keys
   - View usage statistics and quotas

2. **Calendar Data Editors**
   - Register account with verified credentials
   - Request access to specific calendars (national/diocesan)
   - Submit/modify liturgical calendar data
   - Review and approve changes (for administrators)

3. **Administrators**
   - Manage user roles and permissions
   - Review and approve calendar data changes
   - Monitor API usage and abuse
   - Configure system-wide settings

## Technology Stack Options

### Option 1: WorkOS (Recommended for Rapid Development)

**Pros:**

- Enterprise-grade authentication (SSO, MFA)
- Built-in user management and directory sync
- Audit logs and compliance features
- Excellent documentation and SDKs
- Handles RBAC natively

**Cons:**

- Cost scales with users (free tier available)
- External dependency
- Less customization control

**Backend (PHP):**

- `workos/workos-php` SDK
- JWT verification middleware
- Session management

**Frontend (JavaScript):**

- `@workos-inc/authkit-js` for authentication UI
- OAuth 2.0 flows
- Dashboard for user management

### Option 2: Supabase Auth (Good Balance)

**Pros:**

- Open source and self-hostable
- PostgreSQL-based (good for relational data)
- Real-time subscriptions (useful for admin dashboards)
- Built-in storage and database
- Row-level security for fine-grained access

**Cons:**

- Younger ecosystem than WorkOS/Auth0
- Self-hosting requires infrastructure management
- PHP SDK less mature than JS/Python

**Backend (PHP):**

- `supabase-community/supabase-php` SDK
- JWT verification with Supabase signing keys
- PostgreSQL for user/role/API key storage

**Frontend (JavaScript):**

- `@supabase/supabase-js` client
- Pre-built auth UI components
- Real-time dashboard updates

### Option 3: Self-Hosted OAuth 2.0 + JWT (Full Control)

**Pros:**

- Complete control over authentication flow
- No external service dependencies
- No recurring costs
- Data sovereignty

**Cons:**

- More development time
- Security responsibility
- Need to implement all features (MFA, password reset, etc.)
- Ongoing maintenance burden

**Backend (PHP):**

- `league/oauth2-server` for OAuth 2.0 provider
- `firebase/php-jwt` for token generation/validation
- `paragonie/paseto` (alternative to JWT, more secure)
- `spomky-labs/otphp` for MFA/TOTP
- Database schema for users, roles, permissions, API keys

**Frontend (JavaScript):**

- Custom authentication UI
- OAuth 2.0 client library
- Token management and refresh logic

## Recommended Approach: Hybrid Solution

**Use Supabase Auth for user authentication + self-managed API keys**

This provides the best balance of:

- Rapid authentication implementation (Supabase)
- Full control over API key lifecycle
- Flexibility for custom rate limiting and statistics
- PostgreSQL integration for relational data

## Implementation Roadmap

### Phase 1: Infrastructure Setup (Weeks 1-2)

#### Backend API

1. **Database Schema Design**
   - Users table (synced from Supabase Auth or self-managed)
   - Roles table (developer, editor, admin)
   - Permissions table (calendar-specific permissions)
   - Applications table (registered apps by developers)
   - API keys table (keys, quotas, rate limits)
   - API usage statistics table (request logs, endpoint tracking)
   - User-calendar associations (which users can edit which calendars)

2. **Dependencies Installation**

   ```bash
   # For Supabase approach
   composer require supabase-community/supabase-php
   composer require firebase/php-jwt
   composer require guzzlehttp/guzzle  # Already installed

   # For self-hosted approach
   composer require league/oauth2-server
   composer require firebase/php-jwt
   composer require spomky-labs/otphp
   composer require ramsey/uuid

   # For all approaches
   composer require predis/predis  # Redis for rate limiting
   composer require symfony/rate-limiter
   ```

3. **Environment Configuration**

   ```env
   # .env additions
   AUTH_PROVIDER=supabase  # or workos, self-hosted
   SUPABASE_URL=https://your-project.supabase.co
   SUPABASE_ANON_KEY=your-anon-key
   SUPABASE_SERVICE_ROLE_KEY=your-service-role-key
   JWT_SECRET=your-jwt-secret
   JWT_ALGORITHM=HS256
   API_KEY_HEADER=X-API-Key
   REDIS_HOST=localhost
   REDIS_PORT=6379
   ```

#### Frontend Website

1. **Authentication UI Setup**
   - Login/registration pages
   - Password reset flow
   - Email verification
   - Profile management

2. **Developer Dashboard**
   - Application management interface
   - API key generation and display
   - Usage statistics visualization
   - Documentation for API consumption

3. **Calendar Editor Dashboard**
   - Calendar selection interface
   - Data editing forms
   - Change review workflow
   - Permission request system

### Phase 2: Authentication Core (Weeks 3-4)

#### Backend Implementation

1. **Create Authentication Middleware**

   ```php
   // src/Http/Middleware/AuthenticationMiddleware.php
   // Validates JWT tokens from Supabase or self-hosted OAuth
   // Extracts user information and attaches to request
   ```

2. **Create API Key Middleware**

   ```php
   // src/Http/Middleware/ApiKeyMiddleware.php
   // Validates API keys from X-API-Key header
   // Tracks usage statistics
   // Enforces rate limits per key
   ```

3. **Create Authorization Middleware**

   ```php
   // src/Http/Middleware/AuthorizationMiddleware.php
   // Checks user roles and permissions
   // Validates calendar-specific access
   ```

4. **Update Router**
   - Add middleware pipeline configuration
   - Protect PUT/PATCH/DELETE routes
   - Allow GET routes with optional API key (for tracking)

5. **Create Auth Models**

   ```php
   // src/Models/Auth/User.php
   // src/Models/Auth/Role.php
   // src/Models/Auth/Permission.php
   // src/Models/Auth/Application.php
   // src/Models/Auth/ApiKey.php
   ```

6. **Create Auth Handlers**

   ```php
   // src/Handlers/Auth/LoginHandler.php (if self-hosted)
   // src/Handlers/Auth/RegisterHandler.php (if self-hosted)
   // src/Handlers/Auth/ApiKeyHandler.php
   // src/Handlers/Auth/ApplicationHandler.php
   ```

#### Frontend Implementation

1. **Integrate Supabase Auth**

   ```javascript
   // Authentication context/provider
   // Login/logout flows
   // Session management
   // Protected routes
   ```

2. **Developer Portal Pages**
   - `/dashboard/applications` - List and manage apps
   - `/dashboard/applications/new` - Register new app
   - `/dashboard/applications/:id` - App details and API keys
   - `/dashboard/usage` - Usage statistics and analytics

3. **Calendar Editor Pages**
   - `/dashboard/calendars` - List accessible calendars
   - `/dashboard/calendars/:id/edit` - Edit calendar data
   - `/dashboard/permissions` - Request access to calendars

### Phase 3: API Key Management (Weeks 5-6)

#### Backend Implementation

1. **API Key Generation Service**

   ```php
   // src/Services/ApiKeyService.php
   class ApiKeyService {
      public function generateKey(int $applicationId, string $prefix = 'litcal'): string
      public function validateKey(string $key): ?ApiKey
      public function revokeKey(string $key): bool
      public function rotateKey(string $oldKey): string
      public function recordUsage(string $key, string $endpoint, string $method): void
   }
   ```

2. **Rate Limiting Service**

   ```php
   // src/Services/RateLimitService.php
   class RateLimitService {
      public function checkLimit(string $apiKey, int $maxRequests = 1000): bool
      public function incrementUsage(string $apiKey): void
      public function getRemainingQuota(string $apiKey): int
      public function resetQuota(string $apiKey): void
   }
   ```

3. **Usage Statistics Service**

   ```php
   // src/Services/UsageStatisticsService.php
   class UsageStatisticsService {
      public function recordRequest(string $apiKey, string $endpoint, string $method): void
      public function getStatsByApplication(int $appId, ?DateTimeInterface $from, ?DateTimeInterface $to): array
      public function getEndpointUsage(int $appId): array
      public function getVersionUsage(int $appId): array
   }
   ```

4. **New API Endpoints**

   POST   /auth/applications              - Create application
   GET    /auth/applications              - List user's applications
   GET    /auth/applications/:id          - Get application details
   PATCH  /auth/applications/:id          - Update application
   DELETE /auth/applications/:id          - Delete application

   POST   /auth/applications/:id/keys     - Generate new API key
   GET    /auth/applications/:id/keys     - List application keys
   DELETE /auth/keys/:id                  - Revoke API key
   POST   /auth/keys/:id/rotate           - Rotate API key

   GET    /auth/applications/:id/usage    - Get usage statistics
   GET    /auth/usage/summary             - Get aggregated usage

#### Frontend Implementation

1. **Application Management UI**
   - Create application form (name, description, website, callback URLs)
   - Application list with status indicators
   - Edit application details
   - Delete application with confirmation

2. **API Key Management UI**
   - Display API keys with copy-to-clipboard
   - One-time display of new keys with security warning
   - Revoke keys with confirmation
   - Rotate keys with automated process
   - Key usage indicators

3. **Usage Statistics Dashboard**
   - Request count graphs (daily/weekly/monthly)
   - Endpoint usage breakdown
   - API version distribution
   - Error rate monitoring
   - Quota usage indicators

### Phase 4: Role-Based Access Control (Weeks 7-8)

#### Backend Implementation

1. **Permission System**

   ```php
   // src/Services/PermissionService.php
   class PermissionService {
      public function hasPermission(int $userId, string $permission, ?string $calendarId = null): bool
      public function grantPermission(int $userId, string $permission, ?string $calendarId = null): void
      public function revokePermission(int $userId, string $permission, ?string $calendarId = null): void
      public function getUserPermissions(int $userId): array
      public function getCalendarEditors(string $calendarId): array
   }
   ```

2. **Permission Definitions**

   ```php
   // src/Enum/Permission.php
   enum Permission: string {
      // Developer permissions
      case CREATE_APPLICATION = 'create:application';
      case MANAGE_OWN_APPLICATIONS = 'manage:own:applications';

      // Calendar data permissions
      case VIEW_CALENDAR = 'view:calendar';
      case EDIT_NATIONAL_CALENDAR = 'edit:calendar:national';
      case EDIT_DIOCESAN_CALENDAR = 'edit:calendar:diocesan';
      case EDIT_WIDER_REGION_CALENDAR = 'edit:calendar:wider_region';
      case APPROVE_CALENDAR_CHANGES = 'approve:calendar:changes';

      // Admin permissions
      case MANAGE_USERS = 'manage:users';
      case MANAGE_ROLES = 'manage:roles';
      case VIEW_ALL_STATISTICS = 'view:statistics:all';
      case MANAGE_API_KEYS = 'manage:api_keys:all';
   }
   ```

3. **Role Definitions**

   ```php
   // src/Enum/Role.php
   enum Role: string {
      case DEVELOPER = 'developer';
      case CALENDAR_EDITOR = 'calendar_editor';
      case CALENDAR_ADMIN = 'calendar_admin';
      case SYSTEM_ADMIN = 'system_admin';

      public function getPermissions(): array;
   }
   ```

4. **Authorization Middleware Enhancement**
   - Check user role
   - Validate calendar-specific permissions
   - Return 403 Forbidden for unauthorized access
   - Log authorization failures

5. **Calendar Access Endpoints**

   POST   /auth/permissions/request        - Request calendar access
   GET    /auth/permissions                - List user permissions
   GET    /auth/calendars/accessible       - List calendars user can edit

   **Admin only**
   GET    /admin/permissions               - List all permissions
   POST   /admin/permissions               - Grant permission
   DELETE /admin/permissions/:id           - Revoke permission
   GET    /admin/calendars/:id/editors     - List editors for calendar

#### Frontend Implementation

1. **Permission Request Workflow**
   - Form to request calendar editing access
   - Justification/credential submission
   - Status tracking (pending/approved/denied)
   - Email notifications

2. **Admin Dashboard**
   - Pending permission requests
   - User management interface
   - Role assignment UI
   - Calendar editor assignments
   - Audit log viewer

3. **Calendar Editor UI**
   - List of editable calendars
   - Calendar data editing forms (PUT/PATCH)
   - Validation and preview before submission
   - Change history viewer
   - Delete confirmation dialogs

### Phase 5: Security Hardening (Weeks 9-10)

#### Backend Implementation

1. **Security Enhancements**
   - HTTPS enforcement
   - CORS configuration for specific origins
   - Request signature validation (HMAC)
   - IP whitelisting for admin operations
   - Brute force protection (login attempts)
   - API key rotation policies
   - Suspicious activity detection

2. **Audit Logging**

   ```php
   // src/Services/AuditLogService.php
   class AuditLogService {
      public function logAuthentication(int $userId, bool $success, string $ip): void
      public function logAuthorization(int $userId, string $action, string $resource, bool $granted): void
      public function logApiKeyUsage(string $apiKey, string $endpoint, int $statusCode): void
      public function logDataModification(int $userId, string $calendarId, string $action, array $changes): void
      public function getAuditLog(array $filters): array
   }
   ```

3. **Rate Limiting Tiers**

   ```php
   // Different rate limits based on user type
   const RATE_LIMITS = [
      'anonymous' => 100,      // requests per hour
      'developer' => 1000,     // requests per hour
      'editor' => 500,         // requests per hour
      'admin' => 10000,        // requests per hour
   ];
   ```

4. **API Key Scopes**

   ```php
   // src/Enum/ApiKeyScope.php
   enum ApiKeyScope: string {
      case READ_ONLY = 'read';
      case READ_WRITE = 'read_write';
      case ADMIN = 'admin';
   }
   ```

5. **Webhook System (Optional)**
   - Notify applications of calendar updates
   - Webhook signature verification
   - Retry mechanism for failed deliveries

#### Frontend Implementation

1. **Security Features**
   - HTTPS only
   - Content Security Policy headers
   - Secure cookie settings
   - XSS protection
   - CSRF tokens for forms

2. **User Security Settings**
   - Two-factor authentication setup
   - Active sessions management
   - API key security best practices documentation
   - Security notifications (new login, API key created, etc.)

3. **Admin Security Tools**
   - Audit log viewer with filtering
   - Suspicious activity alerts
   - Failed authentication attempts monitor
   - API abuse detection dashboard

### Phase 6: Testing & Documentation (Weeks 11-12)

#### Backend Testing

1. **PHPUnit Tests**

   ```bash
   # New test suites
   phpunit_tests/Auth/
   ├── AuthenticationMiddlewareTest.php
   ├── ApiKeyMiddlewareTest.php
   ├── AuthorizationMiddlewareTest.php
   ├── ApiKeyServiceTest.php
   ├── RateLimitServiceTest.php
   ├── PermissionServiceTest.php
   └── AuditLogServiceTest.php
   ```

2. **Integration Tests**
   - Full authentication flow
   - API key generation and usage
   - Permission checking
   - Rate limiting behavior
   - Multi-user scenarios

3. **Security Tests**
   - JWT token tampering
   - API key brute force
   - Permission bypass attempts
   - SQL injection prevention
   - XSS prevention

#### Documentation

1. **API Documentation Updates**
   - Authentication section in OpenAPI spec
   - API key usage examples
   - Rate limiting documentation
   - Error codes for auth failures
   - Security best practices

2. **Developer Guides**
   - "Getting Started" guide for new developers
   - API key management tutorial
   - Rate limiting and quotas explanation
   - Code examples in multiple languages (PHP, JavaScript, Python, etc.)
   - Migration guide for existing consumers

3. **Calendar Editor Guides**
   - How to request calendar editing access
   - Calendar data schema documentation
   - Editing workflow and approval process
   - Best practices for data submission

4. **Admin Guides**
   - User and role management
   - Permission granting workflow
   - Monitoring and analytics
   - Security incident response

## Database Schema

### Users Table

   ```sql
   CREATE TABLE users (
      id SERIAL PRIMARY KEY,
      uuid UUID UNIQUE NOT NULL DEFAULT gen_random_uuid(),
      email VARCHAR(255) UNIQUE NOT NULL,
      name VARCHAR(255) NOT NULL,
      role VARCHAR(50) NOT NULL DEFAULT 'developer',
      email_verified BOOLEAN DEFAULT FALSE,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      last_login_at TIMESTAMP,
      is_active BOOLEAN DEFAULT TRUE
   );

   CREATE INDEX idx_users_email ON users(email);
   CREATE INDEX idx_users_uuid ON users(uuid);
   ```

### Applications Table

   ```sql
   CREATE TABLE applications (
      id SERIAL PRIMARY KEY,
      user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      name VARCHAR(255) NOT NULL,
      description TEXT,
      website VARCHAR(500),
      callback_url VARCHAR(500),
      is_active BOOLEAN DEFAULT TRUE,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
   );

   CREATE INDEX idx_applications_user_id ON applications(user_id);
   ```

### API Keys Table

   ```sql
   CREATE TABLE api_keys (
      id SERIAL PRIMARY KEY,
      application_id INTEGER NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
      key_hash VARCHAR(255) UNIQUE NOT NULL,  -- Hashed API key
      key_prefix VARCHAR(20) NOT NULL,         -- First few chars for identification
      scope VARCHAR(50) NOT NULL DEFAULT 'read',
      rate_limit_per_hour INTEGER DEFAULT 1000,
      quota_limit_per_month INTEGER,
      is_active BOOLEAN DEFAULT TRUE,
      last_used_at TIMESTAMP,
      expires_at TIMESTAMP,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      revoked_at TIMESTAMP
   );

   CREATE INDEX idx_api_keys_hash ON api_keys(key_hash);
   CREATE INDEX idx_api_keys_app_id ON api_keys(application_id);
   ```

### API Usage Statistics Table

   ```sql
   CREATE TABLE api_usage_stats (
      id SERIAL PRIMARY KEY,
      api_key_id INTEGER REFERENCES api_keys(id) ON DELETE SET NULL,
      endpoint VARCHAR(255) NOT NULL,
      method VARCHAR(10) NOT NULL,
      status_code INTEGER NOT NULL,
      response_time_ms INTEGER,
      ip_address INET,
      user_agent TEXT,
      requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
   );

   CREATE INDEX idx_usage_api_key_id ON api_usage_stats(api_key_id);
   CREATE INDEX idx_usage_requested_at ON api_usage_stats(requested_at);
   CREATE INDEX idx_usage_endpoint ON api_usage_stats(endpoint);
   ```

### Permissions Table

   ```sql
   CREATE TABLE permissions (
      id SERIAL PRIMARY KEY,
      user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      permission VARCHAR(100) NOT NULL,
      calendar_id VARCHAR(50),  -- NULL for global permissions
      granted_by INTEGER REFERENCES users(id),
      granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      expires_at TIMESTAMP,
      UNIQUE(user_id, permission, calendar_id)
   );

   CREATE INDEX idx_permissions_user_id ON permissions(user_id);
   CREATE INDEX idx_permissions_calendar_id ON permissions(calendar_id);
   ```

### Audit Log Table

   ```sql
   CREATE TABLE audit_log (
      id SERIAL PRIMARY KEY,
      user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
      action VARCHAR(100) NOT NULL,
      resource_type VARCHAR(50) NOT NULL,
      resource_id VARCHAR(100),
      details JSONB,
      ip_address INET,
      user_agent TEXT,
      success BOOLEAN DEFAULT TRUE,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
   );

   CREATE INDEX idx_audit_user_id ON audit_log(user_id);
   CREATE INDEX idx_audit_created_at ON audit_log(created_at);
   CREATE INDEX idx_audit_action ON audit_log(action);
   ```

## Migration Strategy

### For Existing API Consumers

1. **Grace Period (6 months)**
   - Announce authentication requirements
   - Provide migration timeline
   - Offer support for migration

2. **Dual Mode Operation**
   - Support both authenticated and anonymous requests
   - Apply stricter rate limits to anonymous requests
   - Track both in usage statistics

3. **Gradual Enforcement**
   - Month 1-2: Warnings for unauthenticated requests
   - Month 3-4: Reduced rate limits for unauthenticated
   - Month 5-6: Encourage migration with support
   - Month 7+: Require authentication for all write operations

### For New Features

- All new sensitive endpoints require authentication from day one
- Read-only endpoints can remain public with API key tracking

## Monitoring & Metrics

### Key Metrics to Track

1. **Authentication**
   - Login success/failure rates
   - Registration conversion rate
   - Email verification rates
   - MFA adoption rate

2. **API Usage**
   - Total requests per day/month
   - Requests per application
   - Endpoint popularity
   - API version distribution
   - Error rates per endpoint

3. **Performance**
   - Authentication middleware latency
   - Rate limiting overhead
   - Database query performance
   - API response times

4. **Security**
   - Failed authentication attempts
   - Revoked API keys
   - Rate limit violations
   - Permission denial events
   - Suspicious activity alerts

## Cost Estimation

### Supabase Approach (Recommended)

- **Free tier**: Up to 50,000 monthly active users
- **Pro tier**: $25/month + usage
- **Self-hosting**: Infrastructure costs only

### Infrastructure Requirements

- **PostgreSQL Database**: User data, API keys, statistics
- **Redis**: Rate limiting, session storage
- **Application Server**: Existing PHP infrastructure
- **Monitoring**: Logs, metrics, alerts

## Success Criteria

1. **Developer Experience**
   - < 5 minutes to register and get first API key
   - Clear documentation and examples
   - Usage statistics accessible in dashboard
   - Support response time < 24 hours

2. **Security**
   - Zero unauthorized data modifications
   - 100% of write operations authenticated
   - All sensitive actions logged
   - GDPR/privacy compliance

3. **Performance**
   - < 10ms authentication overhead
   - < 5ms rate limiting check
   - 99.9% uptime for auth service
   - No degradation in API response times

4. **Adoption**
   - 80% of active developers registered within 6 months
   - 90% of API requests tracked within 12 months
   - 100% of write operations authenticated within 12 months

## Next Steps

1. Review and approve this roadmap
2. Choose authentication provider (Supabase recommended)
3. Set up development environment with Supabase/chosen provider
4. Begin Phase 1: Infrastructure Setup
5. Iterate on feedback from early adopters
