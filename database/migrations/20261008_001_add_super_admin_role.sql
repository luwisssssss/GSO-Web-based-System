-- R2A: preserve all existing roles and account records; no automatic promotion.
ALTER TABLE users
    MODIFY COLUMN role ENUM('Admin','Borrower','GSO Staff','Director','Super Admin')
    NOT NULL DEFAULT 'Borrower';
