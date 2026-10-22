-- Named print layouts for invoices / delivery notes: title text and which fields are printed.
CREATE TABLE IF NOT EXISTS print_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  kind ENUM('invoice','delivery') NOT NULL,
  settings TEXT NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_print_tpl (name, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
