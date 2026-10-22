-- Delivery trips are decided per invoice / delivery note: either a trip is added for it (driver chosen, cost
-- typed later from the trips page) or the invoice is marked as "no trip needed".
ALTER TABLE sales_invoices ADD COLUMN no_trip TINYINT(1) NOT NULL DEFAULT 0;
