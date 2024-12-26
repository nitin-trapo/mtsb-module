ALTER TABLE commissions
ADD CONSTRAINT unique_order_agent UNIQUE (order_id, agent_id);
