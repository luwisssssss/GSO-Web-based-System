-- R1: additive lifecycle change only. Existing request rows are not converted.
ALTER TABLE resource_requests
    MODIFY COLUMN status ENUM('Pending','Under Review','Approved','Rejected','Cancelled','Released','Return','Returned','Completed')
    NOT NULL DEFAULT 'Pending';
