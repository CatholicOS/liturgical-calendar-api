-- Migration: Convert integer IDs to UUIDs
-- Requires pgcrypto extension (created in 001_create_rbac_tables.sql)
-- Note: CREATE EXTENSION causes implicit commit, so it must be run before this migration

-- Wrap all DDL changes in a transaction for atomic execution
BEGIN;

-- ============================================
-- role_requests table
-- ============================================
ALTER TABLE role_requests ADD COLUMN IF NOT EXISTS uuid_id UUID DEFAULT gen_random_uuid();
UPDATE role_requests SET uuid_id = gen_random_uuid() WHERE uuid_id IS NULL;
ALTER TABLE role_requests ALTER COLUMN uuid_id SET NOT NULL;
ALTER TABLE role_requests DROP CONSTRAINT IF EXISTS role_requests_pkey;
ALTER TABLE role_requests DROP COLUMN IF EXISTS id;
ALTER TABLE role_requests RENAME COLUMN uuid_id TO id;
ALTER TABLE role_requests ADD PRIMARY KEY (id);

-- ============================================
-- user_calendar_permissions table
-- ============================================
ALTER TABLE user_calendar_permissions ADD COLUMN IF NOT EXISTS uuid_id UUID DEFAULT gen_random_uuid();
UPDATE user_calendar_permissions SET uuid_id = gen_random_uuid() WHERE uuid_id IS NULL;
ALTER TABLE user_calendar_permissions ALTER COLUMN uuid_id SET NOT NULL;
ALTER TABLE user_calendar_permissions DROP CONSTRAINT IF EXISTS user_calendar_permissions_pkey;
ALTER TABLE user_calendar_permissions DROP COLUMN IF EXISTS id;
ALTER TABLE user_calendar_permissions RENAME COLUMN uuid_id TO id;
ALTER TABLE user_calendar_permissions ADD PRIMARY KEY (id);

-- ============================================
-- permission_requests table
-- ============================================
ALTER TABLE permission_requests ADD COLUMN IF NOT EXISTS uuid_id UUID DEFAULT gen_random_uuid();
UPDATE permission_requests SET uuid_id = gen_random_uuid() WHERE uuid_id IS NULL;
ALTER TABLE permission_requests ALTER COLUMN uuid_id SET NOT NULL;
ALTER TABLE permission_requests DROP CONSTRAINT IF EXISTS permission_requests_pkey;
ALTER TABLE permission_requests DROP COLUMN IF EXISTS id;
ALTER TABLE permission_requests RENAME COLUMN uuid_id TO id;
ALTER TABLE permission_requests ADD PRIMARY KEY (id);

-- ============================================
-- applications table (already has uuid column, just need to make it the PK)
-- ============================================
-- First, update api_keys to reference the uuid instead of integer id
ALTER TABLE api_keys ADD COLUMN IF NOT EXISTS application_uuid UUID;
UPDATE api_keys SET application_uuid = (SELECT uuid FROM applications WHERE applications.id = api_keys.application_id);
ALTER TABLE api_keys DROP CONSTRAINT IF EXISTS api_keys_application_id_fkey;
ALTER TABLE api_keys DROP COLUMN IF EXISTS application_id;
ALTER TABLE api_keys RENAME COLUMN application_uuid TO application_id;

-- Now convert applications primary key
ALTER TABLE applications DROP CONSTRAINT IF EXISTS applications_pkey;
ALTER TABLE applications DROP CONSTRAINT IF EXISTS applications_uuid_key;
ALTER TABLE applications DROP COLUMN IF EXISTS id;
ALTER TABLE applications RENAME COLUMN uuid TO id;
ALTER TABLE applications ADD PRIMARY KEY (id);

-- Add foreign key constraint back
ALTER TABLE api_keys ADD CONSTRAINT api_keys_application_id_fkey
    FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE;

-- ============================================
-- api_keys table
-- ============================================
ALTER TABLE api_keys ADD COLUMN IF NOT EXISTS uuid_id UUID DEFAULT gen_random_uuid();
UPDATE api_keys SET uuid_id = gen_random_uuid() WHERE uuid_id IS NULL;
ALTER TABLE api_keys ALTER COLUMN uuid_id SET NOT NULL;
ALTER TABLE api_keys DROP CONSTRAINT IF EXISTS api_keys_pkey;
ALTER TABLE api_keys DROP COLUMN IF EXISTS id;
ALTER TABLE api_keys RENAME COLUMN uuid_id TO id;
ALTER TABLE api_keys ADD PRIMARY KEY (id);

-- ============================================
-- audit_log table
-- ============================================
ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS uuid_id UUID DEFAULT gen_random_uuid();
UPDATE audit_log SET uuid_id = gen_random_uuid() WHERE uuid_id IS NULL;
ALTER TABLE audit_log ALTER COLUMN uuid_id SET NOT NULL;
ALTER TABLE audit_log DROP CONSTRAINT IF EXISTS audit_log_pkey;
ALTER TABLE audit_log DROP COLUMN IF EXISTS id;
ALTER TABLE audit_log RENAME COLUMN uuid_id TO id;
ALTER TABLE audit_log ADD PRIMARY KEY (id);

COMMIT;
