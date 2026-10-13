-- Seed 004: auth-related settings (safe to re-run)
-- Password reset stays DISABLED until SMTP is configured AND both the env flag
-- PASSWORD_RESET_ENABLED=true and this setting are enabled. See docs/api-v1.md.
INSERT INTO settings (setting_key, setting_value, description) VALUES
('auth.password_reset_enabled','0','Password-reset issuance disabled by default. Set to 1 AND PASSWORD_RESET_ENABLED=true with SMTP configured to enable.')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
