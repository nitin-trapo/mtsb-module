ALTER TABLE commissions
ADD COLUMN payment_note TEXT NULL AFTER status,
ADD COLUMN payment_receipt VARCHAR(255) NULL AFTER payment_note,
ADD COLUMN paid_at DATETIME NULL AFTER payment_receipt,
ADD COLUMN paid_by INT NULL AFTER paid_at,
ADD FOREIGN KEY (paid_by) REFERENCES users(id);
