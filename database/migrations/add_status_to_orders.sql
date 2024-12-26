-- Add status column if it doesn't exist
ALTER TABLE orders
ADD COLUMN IF NOT EXISTS order_status ENUM('pending', 'processing', 'completed', 'cancelled') DEFAULT 'pending';

-- Update existing orders to have a status if null
UPDATE orders SET order_status = 'pending' WHERE order_status IS NULL;
