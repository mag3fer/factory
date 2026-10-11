-- Phase 2: sales invoices (scan carton barcodes), returns, customer/supplier payments, opening balances.
SET NAMES utf8mb4;

ALTER TABLE parties
  ADD COLUMN opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'positive = they owe us',
  ADD COLUMN opening_date DATE NULL;

-- type sale: customer owes us `total`; type return: we owe the customer `total` back
CREATE TABLE IF NOT EXISTS sales_invoices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type ENUM('sale','return') NOT NULL DEFAULT 'sale',
  number INT NOT NULL,
  inv_date DATE NOT NULL,
  customer_id INT NOT NULL,
  ref_invoice_id INT NULL,
  subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
  discount DECIMAL(14,2) NOT NULL DEFAULT 0,
  total DECIMAL(14,2) NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  voided TINYINT(1) NOT NULL DEFAULT 0,
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sale_number (type, number),
  KEY idx_sale_customer (customer_id, inv_date),
  CONSTRAINT fk_sale_customer FOREIGN KEY (customer_id) REFERENCES parties(id),
  CONSTRAINT fk_sale_ref FOREIGN KEY (ref_invoice_id) REFERENCES sales_invoices(id),
  CONSTRAINT fk_sale_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  item_id INT NOT NULL,
  source ENUM('packages','manual') NOT NULL,
  packages_count INT NOT NULL DEFAULT 0,
  pieces INT NOT NULL DEFAULT 0,
  qty DECIMAL(14,3) NOT NULL,
  price DECIMAL(14,4) NOT NULL DEFAULT 0,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_sl_inv FOREIGN KEY (invoice_id) REFERENCES sales_invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_sl_item FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- which cartons went out on (or came back with) which invoice
CREATE TABLE IF NOT EXISTS sales_packages (
  invoice_id INT NOT NULL,
  package_id INT NOT NULL,
  line_id INT NOT NULL,
  PRIMARY KEY (invoice_id, package_id),
  KEY idx_sp_pkg (package_id),
  CONSTRAINT fk_sp_inv FOREIGN KEY (invoice_id) REFERENCES sales_invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_sp_pkg FOREIGN KEY (package_id) REFERENCES packages(id),
  CONSTRAINT fk_sp_line FOREIGN KEY (line_id) REFERENCES sales_lines(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- receipt: money in (from a customer); payment: money out (to a supplier, or refund to a customer)
CREATE TABLE IF NOT EXISTS payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type ENUM('receipt','payment') NOT NULL,
  number INT NOT NULL,
  pay_date DATE NOT NULL,
  party_id INT NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  method ENUM('cash','bank','cheque','other') NOT NULL DEFAULT 'cash',
  ref_no VARCHAR(50) NULL,
  invoice_id INT NULL,
  notes VARCHAR(255) NULL,
  voided TINYINT(1) NOT NULL DEFAULT 0,
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pay_number (type, number),
  KEY idx_pay_party (party_id, pay_date),
  CONSTRAINT fk_pay_party FOREIGN KEY (party_id) REFERENCES parties(id),
  CONSTRAINT fk_pay_inv FOREIGN KEY (invoice_id) REFERENCES sales_invoices(id),
  CONSTRAINT fk_pay_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE stock_moves MODIFY source ENUM('doc','package','sale') NOT NULL;
