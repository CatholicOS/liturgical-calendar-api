-- Add requested_scope column to applications table
-- Allows developers to request read-only or read+write access
-- which restricts API key generation capabilities after approval

-- Add requested_scope column (without constraint for idempotency)
ALTER TABLE applications
ADD COLUMN IF NOT EXISTS requested_scope VARCHAR(10) DEFAULT 'read';

-- Add check constraint separately (idempotent)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'chk_application_requested_scope'
    ) THEN
        ALTER TABLE applications ADD CONSTRAINT chk_application_requested_scope
            CHECK (requested_scope IN ('read', 'write'));
    END IF;
END $$;

-- Backward compatibility: Set existing applications to 'read' (safe default)
-- (only affects rows where requested_scope is still NULL)
UPDATE applications SET requested_scope = 'read' WHERE requested_scope IS NULL;
