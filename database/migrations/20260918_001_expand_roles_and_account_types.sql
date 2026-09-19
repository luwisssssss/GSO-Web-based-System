-- Phase 1: expand authorization roles without rewriting or reclassifying users.
-- Legacy Borrowers intentionally retain NULL account_type until confirmed.
ALTER TABLE users
    MODIFY COLUMN role ENUM('Admin','Borrower','GSO Staff','Director') NOT NULL DEFAULT 'Borrower',
    ADD COLUMN account_type ENUM('Student','Faculty','University Staff') NULL AFTER role;
