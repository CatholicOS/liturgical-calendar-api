-- Migration: Add 'revoked' status to role_requests table
-- This allows administrators to revoke previously approved role requests

-- Drop and recreate the constraint to include 'revoked'
ALTER TABLE role_requests DROP CONSTRAINT IF EXISTS chk_role_request_status;
ALTER TABLE role_requests ADD CONSTRAINT chk_role_request_status
    CHECK (status IN ('pending', 'approved', 'rejected', 'revoked'));
