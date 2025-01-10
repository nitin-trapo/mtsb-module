ALTER TABLE customers
ADD COLUMN bank_name VARCHAR(255) DEFAULT NULL AFTER commission_rate,
ADD COLUMN bank_account_number VARCHAR(255) DEFAULT NULL AFTER bank_name,
ADD COLUMN bank_account_header VARCHAR(255) DEFAULT NULL AFTER bank_account_number;
