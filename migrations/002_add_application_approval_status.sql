-- Add approval status workflow to applications table
-- Run this migration to enable application approval workflow

-- Add status column with check constraint
ALTER TABLE applications
ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'pending'
    CONSTRAINT chk_application_status CHECK (status IN ('pending', 'approved', 'rejected', 'revoked'));

-- Add admin review tracking columns
ALTER TABLE applications
ADD COLUMN IF NOT EXISTS reviewed_by VARCHAR(255),
ADD COLUMN IF NOT EXISTS review_notes TEXT,
ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMP;

-- Create index for status filtering
CREATE INDEX IF NOT EXISTS idx_applications_status ON applications(status);

-- Backward compatibility: Set existing applications to approved
-- (only affects rows where status is still NULL)
UPDATE applications SET status = 'approved' WHERE status IS NULL;
