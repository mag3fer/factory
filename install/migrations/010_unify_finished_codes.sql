-- Finished-yarn codes follow the Mohtaseb groups: 1301 = 150/144 (new lot 13011xx), 1303 = 300/96, 1305 = 70/36.
-- Idempotent: each statement only touches rows still in the old form.
UPDATE items SET code=CONCAT('1305',SUBSTRING(code,5)) WHERE type='finished' AND code LIKE '1303%' AND name LIKE '70/36%';
UPDATE items SET code=CONCAT('1303',SUBSTRING(code,5)) WHERE type='finished' AND code LIKE '1304%' AND name LIKE '300/96%';
UPDATE items SET code=CONCAT('13011',SUBSTRING(code,6)) WHERE type='finished' AND code LIKE '1302%' AND name LIKE '150/144%';
-- The long double-lot name does not fit on the sticker: keep the first lot only.
UPDATE items SET name=REPLACE(name,' / NP4014J5','') WHERE type IN ('finished','raw') AND code NOT IN ('11001001','11001005') AND name LIKE '%P02P02001J7 / NP4014J5%';
UPDATE items SET lot_no=REPLACE(lot_no,' / NP4014J5','') WHERE code LIKE '13011%';
