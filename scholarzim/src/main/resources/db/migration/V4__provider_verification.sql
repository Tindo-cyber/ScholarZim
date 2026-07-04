CREATE TABLE IF NOT EXISTS provider_profiles (
    profile_id              BIGINT       NOT NULL AUTO_INCREMENT,
    user_id                 BIGINT       NOT NULL,
    organisation_type       VARCHAR(50)  NOT NULL,
    registration_number     VARCHAR(100) NOT NULL,
    certificate_path        VARCHAR(255) NOT NULL,
    certificate_filename    VARCHAR(255) NOT NULL,
    submitted_at            DATETIME(6)  NOT NULL,
    reviewed_at             DATETIME(6)  NULL,
    reviewed_by             VARCHAR(255) NULL,
    rejection_reason        VARCHAR(500) NULL,
    PRIMARY KEY (profile_id),
    UNIQUE KEY uk_provider_profile_user (user_id),
    CONSTRAINT fk_provider_profile_user FOREIGN KEY (user_id) REFERENCES users (user_id)
) ENGINE=InnoDB;
