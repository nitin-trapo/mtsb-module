-- Create orders table
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shopify_order_id BIGINT UNIQUE,
    order_number VARCHAR(50),
    email VARCHAR(255),
    total_price DECIMAL(10, 2),
    subtotal_price DECIMAL(10, 2),
    total_tax DECIMAL(10, 2),
    total_shipping DECIMAL(10, 2),
    currency VARCHAR(3),
    financial_status VARCHAR(50),
    fulfillment_status VARCHAR(50),
    processed_at DATETIME,
    created_at DATETIME,
    updated_at DATETIME,
    line_items TEXT,
    shipping_address TEXT,
    billing_address TEXT,
    discount_codes TEXT,
    discount_applications TEXT,
    customer_id INT,
    FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create commissions table
CREATE TABLE IF NOT EXISTS commissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT,
    agent_id INT,
    amount DECIMAL(10, 2),
    total_discount DECIMAL(10, 2),
    actual_commission DECIMAL(10, 2),
    status VARCHAR(50) DEFAULT 'pending',
    created_at DATETIME,
    updated_at DATETIME,
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (agent_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
