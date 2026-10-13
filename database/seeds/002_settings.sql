-- Seed 002: settings (online applications DISABLED by default per spec)
INSERT INTO settings (setting_key, setting_value, description) VALUES
('applications.online_enabled','0','Online application submission disabled by default. Set to 1 to enable.'),
('site.name_en','Sonatola Upazila Digital Platform','Public site name'),
('site.name_bn','সোনাতলা উপজেলা ডিজিটাল প্ল্যাটফর্ম','Public site name (Bangla)'),
('site.upazila','Sonatola','Upazila name'),
('site.district','Bogura','District name')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
