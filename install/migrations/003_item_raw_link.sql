-- Link each finished-yarn item to the raw POY it is made from (scale screen filters by it).
ALTER TABLE items ADD COLUMN raw_item_id INT NULL AFTER grade,
  ADD CONSTRAINT fk_items_raw FOREIGN KEY (raw_item_id) REFERENCES items(id);

-- the existing finished items all come from the old raw 11001001
UPDATE items f JOIN items r ON r.code = '11001001' SET f.raw_item_id = r.id WHERE f.type = 'finished' AND f.code LIKE '1301%';
