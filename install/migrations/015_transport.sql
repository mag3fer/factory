-- Vehicles, trip prices and trips (goods deliveries paid by the factory; workers' microbus legs).
CREATE TABLE IF NOT EXISTS vehicles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  plate VARCHAR(40) NULL,
  kind ENUM('truck','microbus') NOT NULL DEFAULT 'truck',
  driver_id INT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS trip_rates (
  code ENUM('goods','bus_in','bus_out','bus_empty') PRIMARY KEY,
  label VARCHAR(120) NOT NULL,
  price DECIMAL(12,2) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO trip_rates (code, label, price) VALUES
  ('goods', 'توصيل بضاعة لعميل', 0),
  ('bus_in', 'ميكروباص — جاي بالعمال', 0),
  ('bus_out', 'ميكروباص — رايح بالعمال', 0),
  ('bus_empty', 'ميكروباص — جاي وراح من غير ركاب', 0);

CREATE TABLE IF NOT EXISTS trips (
  id INT AUTO_INCREMENT PRIMARY KEY,
  trip_date DATE NOT NULL,
  vehicle_id INT NULL,
  driver_id INT NOT NULL,
  kind ENUM('goods','bus_in','bus_out','bus_empty') NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  shift ENUM('morning','evening','night') NULL,
  invoice_id INT NULL,
  notes VARCHAR(255) NULL,
  user_id INT NULL,
  voided TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_trip_date (trip_date),
  KEY ix_trip_driver (driver_id, trip_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
