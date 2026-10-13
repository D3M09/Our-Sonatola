-- Seed 003: initial location — Sonatola Upazila only.
-- DO NOT invent union lists or official contacts here.
-- See database/import-official-data.md for verified import procedure.
INSERT IGNORE INTO upazilas (name_en, name_bn, district, division) VALUES
('Sonatola','সোনাতলা','Bogura','Rajshahi');

INSERT IGNORE INTO news_categories (slug, name_en, name_bn) VALUES
('general','General','সাধারণ'),
('announcement','Announcement','ঘোষণা'),
('development','Development','উন্নয়ন');
