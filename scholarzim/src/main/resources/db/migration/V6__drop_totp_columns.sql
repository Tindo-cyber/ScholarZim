-- Drop unused TOTP columns after removing two-factor authentication.
ALTER TABLE users DROP COLUMN totp_secret;
ALTER TABLE users DROP COLUMN totp_enabled;
