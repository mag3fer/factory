-- Standard (fixed 6 cones) vs mixed (cones vary per carton) production. Each mode has its own pallets
-- and its own 1..20 numbering for the same finished count. A pallet can also be "closed" while still
-- partly filled (everything on it was sold, or the operator chose to start a new pallet).
ALTER TABLE pallets ADD COLUMN is_mixed TINYINT(1) NOT NULL DEFAULT 0 AFTER finished_item_id, ADD COLUMN closed TINYINT(1) NOT NULL DEFAULT 0 AFTER full, ADD KEY idx_pallet_mode (finished_item_id, is_mixed, full, closed);
ALTER TABLE packages ADD COLUMN is_mixed TINYINT(1) NOT NULL DEFAULT 0 AFTER cones_count;
