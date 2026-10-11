-- Textile Land factory system — schema (MariaDB 10.4+ / MySQL 5.7+)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  full_name VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','store','scale') NOT NULL DEFAULT 'scale',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  skey VARCHAR(50) PRIMARY KEY,
  svalue TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS warehouses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- type: raw = خام POY, finished = خيط تام, cone = كونات, pack = كرتونة/شكارة, other
CREATE TABLE IF NOT EXISTS items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(200) NOT NULL,
  type ENUM('raw','finished','cone','pack','other') NOT NULL,
  unit ENUM('kg','pcs') NOT NULL DEFAULT 'kg',
  spec VARCHAR(50) NULL,          -- e.g. 150/144 (DTY count)
  poy_spec VARCHAR(50) NULL,      -- e.g. 250D/144F (label on raw pallet)
  lot_no VARCHAR(50) NULL,
  grade VARCHAR(20) NULL,
  color VARCHAR(50) NULL,
  tare_weight DECIMAL(10,3) NOT NULL DEFAULT 0, -- kg per piece (cones / cartons)
  notes VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- suppliers & customers
CREATE TABLE IF NOT EXISTS parties (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  type ENUM('supplier','customer') NOT NULL,
  phone VARCHAR(50) NULL,
  notes VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- stock documents: receipt = استلام خام/مشتريات, issue = صرف للتشغيل, adjust = تسوية, opening = رصيد افتتاحي
CREATE TABLE IF NOT EXISTS docs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type ENUM('receipt','issue','adjust','opening') NOT NULL,
  number INT NOT NULL,
  doc_date DATE NOT NULL,
  party_id INT NULL,
  warehouse_id INT NOT NULL DEFAULT 1,
  ref_no VARCHAR(50) NULL,        -- supplier invoice / permit no.
  notes VARCHAR(255) NULL,
  voided TINYINT(1) NOT NULL DEFAULT 0,
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_doc_number (type, number),
  CONSTRAINT fk_docs_party FOREIGN KEY (party_id) REFERENCES parties(id),
  CONSTRAINT fk_docs_wh FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
  CONSTRAINT fk_docs_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- qty is always positive here; direction comes from doc type (adjust/opening: signed qty allowed)
CREATE TABLE IF NOT EXISTS doc_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  doc_id INT NOT NULL,
  item_id INT NOT NULL,
  pallets INT NULL,
  qty DECIMAL(14,3) NOT NULL,
  pieces INT NOT NULL DEFAULT 0,   -- cheeses / cones count
  price DECIMAL(14,4) NULL,
  notes VARCHAR(255) NULL,
  CONSTRAINT fk_lines_doc FOREIGN KEY (doc_id) REFERENCES docs(id) ON DELETE CASCADE,
  CONSTRAINT fk_lines_item FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- one row per weighed carton / sack at the scale station
CREATE TABLE IF NOT EXISTS packages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  serial VARCHAR(30) NOT NULL UNIQUE,      -- printed as barcode
  finished_item_id INT NOT NULL,
  raw_item_id INT NULL,
  cone_item_id INT NULL,
  cones_count INT NOT NULL DEFAULT 0,
  pack_item_id INT NULL,
  cone_weight DECIMAL(10,3) NOT NULL DEFAULT 0,
  pack_weight DECIMAL(10,3) NOT NULL DEFAULT 0,
  bag_weight DECIMAL(10,3) NOT NULL DEFAULT 0,  -- per cone
  tare DECIMAL(10,3) NOT NULL,
  gross DECIMAL(10,3) NOT NULL,
  net DECIMAL(10,3) NOT NULL,
  weight_source ENUM('scale','manual') NOT NULL DEFAULT 'manual',
  status ENUM('in_stock','sold','void') NOT NULL DEFAULT 'in_stock',
  printed_count INT NOT NULL DEFAULT 0,
  warehouse_id INT NOT NULL DEFAULT 1,
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  voided_at DATETIME NULL,
  void_reason VARCHAR(255) NULL,
  KEY idx_pkg_date (created_at),
  CONSTRAINT fk_pkg_fin FOREIGN KEY (finished_item_id) REFERENCES items(id),
  CONSTRAINT fk_pkg_raw FOREIGN KEY (raw_item_id) REFERENCES items(id),
  CONSTRAINT fk_pkg_cone FOREIGN KEY (cone_item_id) REFERENCES items(id),
  CONSTRAINT fk_pkg_pack FOREIGN KEY (pack_item_id) REFERENCES items(id),
  CONSTRAINT fk_pkg_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- stock ledger: balance of an item = SUM(qty); pieces = SUM(pieces)
CREATE TABLE IF NOT EXISTS stock_moves (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  warehouse_id INT NOT NULL DEFAULT 1,
  qty DECIMAL(14,3) NOT NULL,
  pieces INT NOT NULL DEFAULT 0,
  source ENUM('doc','package') NOT NULL,
  source_id INT NOT NULL,          -- docs.id or packages.id
  move_date DATETIME NOT NULL,
  KEY idx_moves_item (item_id, move_date),
  KEY idx_moves_source (source, source_id),
  CONSTRAINT fk_moves_item FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
