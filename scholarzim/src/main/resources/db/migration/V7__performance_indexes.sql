-- Indexes for common lookup and filter paths.
-- Email uniqueness: skip rows with NULL email (MySQL allows multiple NULLs in UNIQUE).

CREATE UNIQUE INDEX uk_users_email ON users (email);

CREATE INDEX idx_notifications_user_read_created
    ON notifications (user_id, is_read, created_at);

CREATE INDEX idx_opportunities_status_deadline
    ON opportunities (status, deadline);
