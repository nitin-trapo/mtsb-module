-- Create database
CREATE DATABASE IF NOT EXISTS shopify_commission_new;
USE shopify_commission_new;

-- Users table for admin and agents
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'agent') NOT NULL,
    name VARCHAR(255),
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Sync Logs table
CREATE TABLE IF NOT EXISTS sync_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sync_type ENUM('customers', 'orders', 'product_types', 'product_tags') NOT NULL,
    status ENUM('running', 'success', 'failed') NOT NULL,
    items_synced INT DEFAULT 0,
    error_message TEXT,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Customers table (synced from Shopify)
CREATE TABLE IF NOT EXISTS customers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    shopify_customer_id BIGINT UNIQUE,
    email VARCHAR(255),
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    phone VARCHAR(32),
    accepts_marketing BOOLEAN DEFAULT FALSE,
    total_spent DECIMAL(10,2) DEFAULT 0,
    orders_count INT DEFAULT 0,
    tags TEXT,
    addresses TEXT,
    default_address TEXT,
    tax_exempt BOOLEAN DEFAULT FALSE,
    verified_email BOOLEAN DEFAULT FALSE,
    is_agent BOOLEAN DEFAULT FALSE,
    commission_rate DECIMAL(5,2) DEFAULT 0.00,
    status ENUM('active', 'inactive') DEFAULT 'active',
    last_sync_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Orders table (synced from Shopify)
CREATE TABLE IF NOT EXISTS orders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    shopify_order_id BIGINT UNIQUE,
    customer_id INT,
    agent_id INT,
    order_number VARCHAR(32),
    email VARCHAR(255),
    phone VARCHAR(32),
    total_price DECIMAL(10,2),
    subtotal_price DECIMAL(10,2),
    total_tax DECIMAL(10,2),
    total_discounts DECIMAL(10,2),
    total_line_items_discount DECIMAL(10,2),
    total_order_discount DECIMAL(10,2),
    discount_applications TEXT,
    total_shipping DECIMAL(10,2),
    currency VARCHAR(3),
    status ENUM('pending', 'processing', 'completed', 'cancelled') DEFAULT 'pending',
    shopify_status VARCHAR(64),
    financial_status VARCHAR(64),
    fulfillment_status VARCHAR(64),
    payment_gateway_names TEXT,
    shipping_address TEXT,
    billing_address TEXT,
    note TEXT,
    tags TEXT,
    discount_codes TEXT,
    shipping_lines TEXT,
    tax_lines TEXT,
    refunds TEXT,
    line_items LONGTEXT,
    processed_at DATETIME,
    closed_at DATETIME,
    cancelled_at DATETIME,
    cancel_reason VARCHAR(64),
    last_sync_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (agent_id) REFERENCES customers(id)
);

-- Products table (synced from Shopify)
CREATE TABLE IF NOT EXISTS products (
    id INT PRIMARY KEY AUTO_INCREMENT,
    shopify_product_id BIGINT UNIQUE,
    title VARCHAR(255),
    product_type VARCHAR(100),
    vendor VARCHAR(100),
    handle VARCHAR(255),
    status ENUM('active', 'archived', 'draft') DEFAULT 'active',
    tags TEXT,
    last_sync_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Product Variants table
CREATE TABLE IF NOT EXISTS product_variants (
    id INT PRIMARY KEY AUTO_INCREMENT,
    product_id INT,
    shopify_variant_id BIGINT UNIQUE,
    sku VARCHAR(100),
    title VARCHAR(255),
    price DECIMAL(10,2),
    compare_at_price DECIMAL(10,2),
    inventory_quantity INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id)
);

-- Order Items table
CREATE TABLE IF NOT EXISTS order_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_id INT,
    product_id INT,
    variant_id INT,
    shopify_product_id BIGINT,
    shopify_variant_id BIGINT,
    title VARCHAR(255),
    quantity INT,
    price DECIMAL(10,2),
    product_type VARCHAR(100),
    sku VARCHAR(100),
    vendor VARCHAR(100),
    requires_shipping BOOLEAN DEFAULT TRUE,
    taxable BOOLEAN DEFAULT TRUE,
    tags TEXT,
    properties TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (variant_id) REFERENCES product_variants(id)
);

-- Commission Rules table
CREATE TABLE IF NOT EXISTS commission_rules (
    id INT PRIMARY KEY AUTO_INCREMENT,
    rule_type ENUM('product_type', 'product_tag', 'default') NOT NULL,
    rule_value VARCHAR(255) NOT NULL,
    commission_percentage DECIMAL(5,2) NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Commissions table
CREATE TABLE IF NOT EXISTS commissions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_id INT,
    agent_id INT,
    amount DECIMAL(10,2),
    status ENUM('pending', 'approved', 'paid') DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (agent_id) REFERENCES customers(id)
);

-- Invoices table
CREATE TABLE IF NOT EXISTS invoices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    commission_id INT,
    invoice_number VARCHAR(50) UNIQUE,
    amount DECIMAL(10,2),
    status ENUM('draft', 'sent', 'paid') DEFAULT 'draft',
    pdf_path VARCHAR(255),
    sent_at TIMESTAMP NULL,
    paid_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (commission_id) REFERENCES commissions(id)
);

-- Email Logs table
CREATE TABLE IF NOT EXISTS email_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_id INT,
    email_type VARCHAR(50) NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    status ENUM('sent', 'failed') NOT NULL,
    error_message TEXT,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id)
);

-- Insert default admin user
INSERT INTO users (email, password, role, name) 
VALUES ('admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'System Admin')
ON DUPLICATE KEY UPDATE email=email;
