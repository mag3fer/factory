-- Counts 1302xxx (lot P02P02001J7 / NP4014J5) are made from raw POY 250D 11001003.
UPDATE items f JOIN items r ON r.code = '11001003' AND r.type = 'raw' SET f.raw_item_id = r.id WHERE f.type = 'finished' AND f.code LIKE '1302%' AND f.raw_item_id IS NULL;
