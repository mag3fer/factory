-- Packing types, as in the old BarCode station (boxing.txt): each type fixes the tare per cone,
-- per carton/sack and per bag. Weights copied from the scale PC on 2026-10-11.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS packing_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  pack_item_id INT NULL,                 -- carton / sack item deducted from stock
  cone_weight DECIMAL(10,3) NOT NULL,
  pack_weight DECIMAL(10,3) NOT NULL,
  bag_weight DECIMAL(10,3) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_pt_pack FOREIGN KEY (pack_item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE packages ADD COLUMN packing_type_id INT NULL AFTER pack_item_id,
  ADD CONSTRAINT fk_pkg_pt FOREIGN KEY (packing_type_id) REFERENCES packing_types(id);

INSERT INTO packing_types (name, pack_item_id, cone_weight, pack_weight, bag_weight)
SELECT t.name, i.id, t.cw, t.pw, t.bw FROM (
  SELECT 'كرتون / كونات كرتون 200' name, '1202001' code, 0.200 cw, 1.400 pw, 0.015 bw UNION ALL
  SELECT 'كرتون / كونات كرتون 150', '1202001', 0.150, 1.400, 0.015 UNION ALL
  SELECT 'شكارة / كونات كرتون 150', '1202002', 0.150, 0.240, 0.015 UNION ALL
  SELECT 'شكارة / كونات كرتون 200', '1202002', 0.200, 0.240, 0.015 UNION ALL
  SELECT 'كرتون / كونات كرتون 180', '1202001', 0.180, 1.400, 0.006 UNION ALL
  SELECT 'شكارة / كونات كرتون 180', '1202002', 0.180, 0.240, 0.015
) t LEFT JOIN items i ON i.code = t.code;
