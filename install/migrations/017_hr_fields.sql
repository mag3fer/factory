-- Start date (pro-rata first month), allowed rest days per month, paid-free "rest" status and a per-day amount override.
ALTER TABLE workers ADD COLUMN start_date DATE NULL AFTER shift, ADD COLUMN rest_days TINYINT NOT NULL DEFAULT 0 AFTER start_date;
ALTER TABLE attendance MODIFY status ENUM('present','half','absent','rest') NOT NULL DEFAULT 'present', ADD COLUMN day_amount DECIMAL(12,2) NULL AFTER overtime_hours;
