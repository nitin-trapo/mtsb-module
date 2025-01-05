ALTER TABLE commissions
ADD COLUMN adjustment_reason TEXT NULL,
ADD COLUMN adjusted_at DATETIME NULL,
ADD COLUMN adjusted_by INT NULL,
ADD FOREIGN KEY (adjusted_by) REFERENCES users(id);
