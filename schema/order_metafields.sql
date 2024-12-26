-- Add metafields column to orders table
ALTER TABLE orders 
ADD COLUMN IF NOT EXISTS metafields JSON DEFAULT NULL,
ADD COLUMN IF NOT EXISTS metafields_updated_at TIMESTAMP NULL DEFAULT NULL;

-- Add index for better query performance
ALTER TABLE orders 
ADD INDEX idx_metafields_updated (metafields_updated_at);
