-- Workers, drivers, attendance by shift and one personal ledger per person (wages, bonuses, trips, advances, deductions, payments).
CREATE TABLE IF NOT EXISTS workers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(40) NULL,
  role ENUM('worker','goods_driver','bus_driver') NOT NULL DEFAULT 'worker',
  job VARCHAR(80) NULL,
  pay_type ENUM('monthly','daily','trip') NOT NULL DEFAULT 'daily',
  rate DECIMAL(12,2) NOT NULL DEFAULT 0,
  ot_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
  shift ENUM('morning','evening','night') NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  worker_id INT NOT NULL,
  work_date DATE NOT NULL,
  shift ENUM('morning','evening','night') NOT NULL,
  status ENUM('present','half','absent') NOT NULL DEFAULT 'present',
  overtime_hours DECIMAL(5,2) NOT NULL DEFAULT 0,
  user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_att (worker_id, work_date, shift),
  KEY ix_att_date (work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hr_ledger (
  id INT AUTO_INCREMENT PRIMARY KEY,
  worker_id INT NOT NULL,
  entry_date DATE NOT NULL,
  kind ENUM('wage','bonus','trip','advance','deduction','payment') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  period_from DATE NULL,
  period_to DATE NULL,
  ref_id INT NULL,
  notes VARCHAR(255) NULL,
  user_id INT NULL,
  voided TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_led_worker (worker_id, entry_date),
  KEY ix_led_ref (kind, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
