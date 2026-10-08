-- R3: classification only; existing exact times remain authoritative.
ALTER TABLE request_items
    ADD COLUMN facility_slot ENUM('AM','PM') NULL DEFAULT NULL AFTER line_kind,
    ADD CONSTRAINT chk_request_item_facility_slot
        CHECK (facility_slot IS NULL OR line_kind = 'Facility');

-- Authoritative normal operating hours. Preserve any already configured values.
INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
    ('facility_operating_start', '08:00'),
    ('facility_operating_end', '17:00');
