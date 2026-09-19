-- Step 8 security stabilization: `login_attempts` is ephemeral throttle state
-- with a 30-second horizon, so purging it is lossless.
--
-- Earlier development rows stored a raw login identifier (for example
-- 'user:<username>'), which breaks the privacy rule for this table. Keys are
-- now a keyed HMAC-SHA256 and are always 64 lowercase hex characters, so any
-- row failing that shape is a plaintext identifier and is removed.

DELETE FROM `login_attempts`
WHERE `attempt_key` NOT REGEXP '^[0-9a-f]{64}$';
