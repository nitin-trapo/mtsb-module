-- Add bank details columns to customers table
ALTER TABLE customers
ADD COLUMN bank_name VARCHAR(100) DEFAULT NULL,
ADD COLUMN bank_account_number VARCHAR(50) DEFAULT NULL,
ADD COLUMN bank_account_holder VARCHAR(100) DEFAULT NULL,
ADD COLUMN bank_swift_code VARCHAR(20) DEFAULT NULL;
