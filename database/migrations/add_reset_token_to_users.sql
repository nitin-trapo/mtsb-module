ALTER TABLE users 
ADD COLUMN reset_token VARCHAR(255) NULL,
ADD COLUMN reset_token_expiry DATETIME NULL,
ADD COLUMN password_status ENUM('set', 'unset') DEFAULT 'unset';
