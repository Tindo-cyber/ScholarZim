-- ScholarZim schema additions
-- Phase 6: Notification System
--
-- Run this against your existing `scholarzim` database BEFORE restarting the app
-- (the app uses spring.jpa.hibernate.ddl-auto=validate, so the table must exist).

CREATE TABLE IF NOT EXISTS notifications (
    notification_id BIGINT       NOT NULL AUTO_INCREMENT,
    user_id         BIGINT       NOT NULL,
    type            VARCHAR(50)  NOT NULL,
    message         VARCHAR(500) NOT NULL,
    link            VARCHAR(255) NULL,
    related_id      BIGINT       NULL,
    is_read         BIT(1)       NOT NULL DEFAULT 0,
    created_at      DATETIME(6)  NULL,
    PRIMARY KEY (notification_id),
    CONSTRAINT fk_notifications_user
        FOREIGN KEY (user_id) REFERENCES users (user_id),
    INDEX idx_notifications_user_read (user_id, is_read)
) ENGINE = InnoDB;

-- Phase 9: Audit trail
--
-- Run against your existing `scholarzim` database BEFORE restarting the app.

CREATE TABLE IF NOT EXISTS audit_log (
    audit_id     BIGINT       NOT NULL AUTO_INCREMENT,
    actor_email  VARCHAR(255) NOT NULL,
    action       VARCHAR(50)  NOT NULL,
    entity_type  VARCHAR(50)  NOT NULL,
    entity_id    BIGINT       NULL,
    details      TEXT         NULL,
    created_at   DATETIME(6)  NULL,
    PRIMARY KEY (audit_id),
    INDEX idx_audit_created (created_at),
    INDEX idx_audit_actor (actor_email)
) ENGINE = InnoDB;

-- Phase 9: Prevent duplicate applications (skip if constraint already exists)
-- Remove any duplicate rows first if this fails:
--   DELETE a1 FROM applications a1
--   INNER JOIN applications a2
--     ON a1.user_id = a2.user_id AND a1.opportunity_id = a2.opportunity_id
--     AND a1.application_id > a2.application_id;

ALTER TABLE applications
    ADD CONSTRAINT uk_applications_user_opportunity
        UNIQUE (user_id, opportunity_id);
