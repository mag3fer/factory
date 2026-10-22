-- Packing choice is just carton or sack (fixed weights); the cone's weight comes from the chosen cone item.
UPDATE packing_types SET active=0 WHERE name LIKE '%كونات كرتون%';
INSERT INTO packing_types (name, pack_item_id, cone_weight, pack_weight, bag_weight, active)
SELECT 'كرتون', (SELECT id FROM items WHERE code='1202001'), 0, 1.400, 0.015, 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM packing_types WHERE name='كرتون');
INSERT INTO packing_types (name, pack_item_id, cone_weight, pack_weight, bag_weight, active)
SELECT 'شكارة', (SELECT id FROM items WHERE code='1202002'), 0, 0.240, 0.015, 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM packing_types WHERE name='شكارة');
