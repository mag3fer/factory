-- Delivery note: phone of the receiving company's representative, so the driver can call him (not ours).
ALTER TABLE sales_invoices ADD COLUMN contact_phone VARCHAR(40) NULL AFTER receiver;
