-- Add 'default' to rule_type enum in commission_rules table
ALTER TABLE commission_rules 
MODIFY COLUMN rule_type ENUM('product_type', 'product_tag', 'default') NOT NULL;

-- Insert default commission rule if not exists
INSERT INTO commission_rules (rule_type, rule_value, commission_percentage, status)
SELECT 'default', 'default', 5.00, 'active'
WHERE NOT EXISTS (
    SELECT 1 FROM commission_rules WHERE rule_type = 'default'
);
