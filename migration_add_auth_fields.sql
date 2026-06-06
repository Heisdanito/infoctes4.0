-- ============================================================
--  migration_add_auth_fields.sql
--  Run this once to add auth columns to existing tables
-- ============================================================

-- Add PIN hash to student table (used by course reps)
ALTER TABLE student
    ADD COLUMN pin_hash VARCHAR(255) NULL
        COMMENT 'bcrypt hash of 10-digit PIN — only set for course reps'
    AFTER phone;

-- Add password hash to lecturer table
ALTER TABLE lecturer
    ADD COLUMN password_hash VARCHAR(255) NOT NULL DEFAULT ''
        COMMENT 'bcrypt hash of lecturer password'
    AFTER email;

-- ── Example: set a course rep PIN (run in your seed script) ──
-- The raw PIN is hashed using PHP password_hash($pin, PASSWORD_BCRYPT)
-- Example hash below is for PIN "1234567890"
-- UPDATE student SET pin_hash = '$2y$10$examplehashhere' WHERE index_number = 'UEW/ICT/0001/22';

-- ── Example: set a lecturer password ─────────────────────────
-- UPDATE lecturer SET password_hash = '$2y$10$examplehashhere' WHERE email = 'john@uew.edu.gh';
