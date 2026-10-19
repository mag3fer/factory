-- Pallets: every N cartons (setting pallet_size, default 20) of the same finished count are grouped
-- under one pallet serial (P00001...) so a whole pallet can be sold with one scan.
CREATE TABLE IF NOT EXISTS pallets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  serial VARCHAR(20) NOT NULL UNIQUE,
  finished_item_id INT NOT NULL,
  capacity INT NOT NULL DEFAULT 20,
  full TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME NULL,
  KEY idx_pallet_open (finished_item_id, full),
  CONSTRAINT fk_pallet_item FOREIGN KEY (finished_item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE packages ADD COLUMN pallet_id INT NULL AFTER serial, ADD COLUMN pallet_seq SMALLINT NULL AFTER pallet_id, ADD KEY idx_pkg_pallet (pallet_id), ADD CONSTRAINT fk_pkg_pallet FOREIGN KEY (pallet_id) REFERENCES pallets(id);
