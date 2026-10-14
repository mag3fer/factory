-- The 70/36 and 300/96 counts: same grades as the 1302xxx (150/144) counts, only the count and lot differ.
-- 1303xxx = 300/96 from POY 480D/96F (11001004, lot P01P10007J7); 1304xxx = 70/36 from POY 125D/36F (11001002, lot P02P06009J7).
INSERT INTO items (code, name, type, unit, spec, poy_spec, lot_no, grade, color, tare_weight, notes, active, raw_item_id)
SELECT CONCAT('1303', SUBSTRING(f.code, 5)),
       REPLACE(REPLACE(REPLACE(f.name, '150/144', '300/96'), 'P02P02001J7 / NP4014J5', 'P01P10007J7'), COALESCE(NULLIF(f.lot_no, ''), 'P02P02001J7 / NP4014J5'), 'P01P10007J7'),
       'finished', f.unit, '300/96', '480D/96F', 'P01P10007J7', f.grade, f.color, f.tare_weight, f.notes, 1, r.id
FROM items f JOIN items r ON r.code = '11001004' AND r.type = 'raw'
WHERE f.type = 'finished' AND f.code LIKE '1302%'
  AND NOT EXISTS (SELECT 1 FROM items x WHERE x.code = CONCAT('1303', SUBSTRING(f.code, 5)));

INSERT INTO items (code, name, type, unit, spec, poy_spec, lot_no, grade, color, tare_weight, notes, active, raw_item_id)
SELECT CONCAT('1304', SUBSTRING(f.code, 5)),
       REPLACE(REPLACE(REPLACE(f.name, '150/144', '70/36'), 'P02P02001J7 / NP4014J5', 'P02P06009J7'), COALESCE(NULLIF(f.lot_no, ''), 'P02P02001J7 / NP4014J5'), 'P02P06009J7'),
       'finished', f.unit, '70/36', '125D/36F', 'P02P06009J7', f.grade, f.color, f.tare_weight, f.notes, 1, r.id
FROM items f JOIN items r ON r.code = '11001002' AND r.type = 'raw'
WHERE f.type = 'finished' AND f.code LIKE '1302%'
  AND NOT EXISTS (SELECT 1 FROM items x WHERE x.code = CONCAT('1304', SUBSTRING(f.code, 5)));
