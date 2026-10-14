-- Fix for 007: the 1303xxx codes were already the 70/36 counts, so 007 made no 300/96 counts and its
-- 1304xxx rows duplicated 70/36. Turn those 1304xxx rows into the 300/96 counts (POY 480D 11001004).
UPDATE items f JOIN items r ON r.code = '11001004' AND r.type = 'raw'
SET f.name = REPLACE(REPLACE(f.name, '70/36', '300/96'), 'P02P06009J7', 'P01P10007J7'),
    f.spec = '300/96', f.poy_spec = '480D/96F', f.lot_no = 'P01P10007J7', f.raw_item_id = r.id
WHERE f.type = 'finished' AND f.code LIKE '1304%' AND f.spec = '70/36' AND f.poy_spec = '125D/36F';

-- the existing 70/36 counts come from POY 125D 11001002
UPDATE items f JOIN items r ON r.code = '11001002' AND r.type = 'raw'
SET f.raw_item_id = r.id
WHERE f.type = 'finished' AND f.code LIKE '1303%' AND f.spec = '70/36' AND f.raw_item_id IS NULL;
