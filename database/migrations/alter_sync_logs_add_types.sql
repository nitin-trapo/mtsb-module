ALTER TABLE sync_logs 
MODIFY COLUMN sync_type ENUM('customers', 'orders', 'product_types', 'product_tags') NOT NULL;
