-- ScholarZim incremental migrations (Flyway)

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    token_id    BIGINT       NOT NULL AUTO_INCREMENT,
    user_id     BIGINT       NOT NULL,
    token       VARCHAR(255) NOT NULL,
    expires_at  DATETIME(6)  NOT NULL,
    used        BIT(1)       NOT NULL DEFAULT 0,
    PRIMARY KEY (token_id),
    UNIQUE KEY uk_reset_token (token),
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS saved_scholarships (
    saved_id        BIGINT NOT NULL AUTO_INCREMENT,
    user_id         BIGINT NOT NULL,
    opportunity_id  BIGINT NOT NULL,
    saved_at        DATETIME(6) NULL,
    PRIMARY KEY (saved_id),
    UNIQUE KEY uk_saved_user_opp (user_id, opportunity_id),
    CONSTRAINT fk_saved_user FOREIGN KEY (user_id) REFERENCES users (user_id),
    CONSTRAINT fk_saved_opp FOREIGN KEY (opportunity_id) REFERENCES opportunities (opportunity_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tenants (
    tenant_id   BIGINT       NOT NULL AUTO_INCREMENT,
    name        VARCHAR(255) NOT NULL,
    slug        VARCHAR(100) NOT NULL,
    active      BIT(1)       NOT NULL DEFAULT 1,
    PRIMARY KEY (tenant_id),
    UNIQUE KEY uk_tenant_slug (slug)
) ENGINE=InnoDB;
