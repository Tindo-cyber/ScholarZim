-- Minimal pre-Flyway baseline (simulates Hibernate-created core schema)

CREATE TABLE IF NOT EXISTS roles (
    role_id     BIGINT       NOT NULL AUTO_INCREMENT,
    role_name   VARCHAR(50)  NOT NULL,
    description VARCHAR(255) NULL,
    PRIMARY KEY (role_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
    user_id        BIGINT       NOT NULL AUTO_INCREMENT,
    role_id        BIGINT       NULL,
    full_name      VARCHAR(255) NULL,
    email          VARCHAR(255) NULL,
    phone          VARCHAR(50)  NULL,
    password_hash  VARCHAR(255) NULL,
    account_status VARCHAR(50)  NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (role_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS applicant_profiles (
    profile_id        BIGINT       NOT NULL AUTO_INCREMENT,
    user_id           BIGINT       NULL,
    education_level   VARCHAR(100) NULL,
    institution_name  VARCHAR(255) NULL,
    field_of_study    VARCHAR(255) NULL,
    country           VARCHAR(100) NULL,
    province          VARCHAR(100) NULL,
    academic_results  VARCHAR(500) NULL,
    biography         TEXT         NULL,
    PRIMARY KEY (profile_id),
    CONSTRAINT fk_applicant_profile_user FOREIGN KEY (user_id) REFERENCES users (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS opportunities (
    opportunity_id    BIGINT       NOT NULL AUTO_INCREMENT,
    provider_user_id  BIGINT       NULL,
    title             VARCHAR(255) NULL,
    description       TEXT         NULL,
    provider_name     VARCHAR(255) NULL,
    education_level   VARCHAR(100) NULL,
    funding_type      VARCHAR(100) NULL,
    country           VARCHAR(100) NULL,
    deadline          DATE         NULL,
    status            VARCHAR(50)  NULL,
    created_at        DATETIME(6)  NULL,
    target_field      VARCHAR(255) NULL,
    target_country    VARCHAR(255) NULL,
    PRIMARY KEY (opportunity_id),
    CONSTRAINT fk_opportunity_provider FOREIGN KEY (provider_user_id) REFERENCES users (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS applications (
    application_id      BIGINT       NOT NULL AUTO_INCREMENT,
    user_id             BIGINT       NULL,
    opportunity_id      BIGINT       NULL,
    application_status  VARCHAR(50)  NULL,
    submitted_at        DATETIME(6)  NULL,
    personal_statement  TEXT         NULL,
    document_filename   VARCHAR(255) NULL,
    document_path       VARCHAR(255) NULL,
    rejection_reason    VARCHAR(500) NULL,
    PRIMARY KEY (application_id),
    CONSTRAINT fk_application_user FOREIGN KEY (user_id) REFERENCES users (user_id),
    CONSTRAINT fk_application_opportunity FOREIGN KEY (opportunity_id) REFERENCES opportunities (opportunity_id)
) ENGINE=InnoDB;
