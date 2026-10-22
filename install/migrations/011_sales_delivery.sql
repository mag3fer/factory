-- Delivery note (إذن تسليم / مذكرة إخراج): a sale without prices, with driver, car and receiver, printed like the Mohtaseb sheet.
ALTER TABLE sales_invoices
  ADD COLUMN kind ENUM('invoice','delivery') NOT NULL DEFAULT 'invoice' AFTER type,
  ADD COLUMN driver_name VARCHAR(100) NULL,
  ADD COLUMN car_no VARCHAR(50) NULL,
  ADD COLUMN receiver VARCHAR(150) NULL;
UPDATE sales_invoices SET kind='delivery' WHERE type='sale' AND total=0;
