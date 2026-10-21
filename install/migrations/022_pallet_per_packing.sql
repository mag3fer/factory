-- A pallet holds one kind of container: 20 cartons, 15 sacks. The size comes from the packing type
-- (editable from the scale page) and every (count, type نظامي/متعدد, packing type) has its own pallets.
ALTER TABLE packing_types ADD COLUMN pallet_size INT NOT NULL DEFAULT 20;
UPDATE packing_types SET pallet_size=15 WHERE name LIKE 'شكارة%';
ALTER TABLE pallets ADD COLUMN packing_type_id INT NULL AFTER is_mixed, ADD KEY idx_pallet_packing (finished_item_id, is_mixed, packing_type_id, full, closed);
UPDATE pallets pl SET pl.packing_type_id=(SELECT p.packing_type_id FROM packages p WHERE p.pallet_id=pl.id ORDER BY p.id LIMIT 1) WHERE pl.packing_type_id IS NULL;
