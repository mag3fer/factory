-- Name printed under "المنظم" on invoices and delivery notes (empty = company name).
ALTER TABLE sales_invoices ADD COLUMN organizer VARCHAR(100) NULL;
