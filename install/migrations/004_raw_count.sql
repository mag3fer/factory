-- The yarn count each raw POY makes: the scale screen shows only the counts with the same spec.
UPDATE items SET spec = '150/144' WHERE type = 'raw' AND code IN ('11001001', '11001003');
UPDATE items SET spec = '70/36' WHERE type = 'raw' AND code = '11001002';
UPDATE items SET spec = '300/96' WHERE type = 'raw' AND code = '11001004';
