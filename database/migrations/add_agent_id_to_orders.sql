-- Add agent_id column if it doesn't exist
ALTER TABLE orders
ADD COLUMN IF NOT EXISTS agent_id INT,
ADD CONSTRAINT fk_orders_agent
FOREIGN KEY (agent_id) REFERENCES customers(id);
