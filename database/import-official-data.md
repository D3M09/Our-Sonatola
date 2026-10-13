# Importing verified official data (unions, contacts)

Do NOT invent union lists or administrative contact information. This project ships with only:

- `upazilas`: Sonatola, Bogura
- `news_categories`: general, announcement, development

## Procedure for authorized staff

1. Obtain the official union list from the Upazila Nirbahi Office / LGED / EC gazette. Record source + date.
2. Create CSV `unions-official.csv`:
   ```csv
   upazila_name_en,union_name_en,union_name_bn,code
   Sonatola,Name EN,নাম BN,UNION-CODE
   ```
3. Validate every row against the gazette (two-person check). Keep the signed source PDF in a private folder (never commit).
4. Import via MySQL (example):
   ```sql
   -- Resolve upazila id first
   SELECT id FROM upazilas WHERE name_en='Sonatola';
   -- Then insert (replace <UPAZILA_ID>)
   INSERT INTO unions (upazila_id, name_en, name_bn, code, is_active)
   VALUES (<UPAZILA_ID>,'Name EN','নাম BN','UNION-CODE',1)
   ON DUPLICATE KEY UPDATE name_en=VALUES(name_en), name_bn=VALUES(name_bn), is_active=1;
   ```
   Or use `LOAD DATA LOCAL INFILE` with a staging table + review SELECT before final INSERT.
5. Log the import in `audit_logs` (action=`data.import`, entity=`unions`) with source reference.
6. Never import contacts scraped from unofficial sites. Service contacts require written consent from the office.

## What NOT to do

- Do not copy union lists from Wikipedia/blog posts without gazette verification.
- Do not claim official affiliation on the site. Add footer disclaimer: "Unofficial demo platform — verify with Upazila administration."
- Do not integrate with government databases without written authorization.
