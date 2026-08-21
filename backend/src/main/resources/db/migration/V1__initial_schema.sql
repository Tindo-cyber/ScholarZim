-- Core schema for fresh production databases (previously created by Hibernate in dev)

CREATE TABLE IF NOT EXISTS roles (
    role_id     BIGINT       NOT NULL AUTO_INCREMENT,
    role_name   VARCHAR(50)  NOT NULL,
    description VARCHAR(255) NULL,
    PRIMARY KEY (role_id),
    UNIQUE KEY uk_role_name (role_name)
) ENGINE=InnoDB;

INSERT IGNORE INTO roles (role_id, role_name, description) VALUES
    (1, 'ROLE_APPLICANT', 'Scholarship applicant'),
    (2, 'ROLE_PROVIDER', 'Scholarship provider'),
    (3, 'ROLE_ADMIN', 'Platform administrator');

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
    profile_id       BIGINT       NOT NULL AUTO_INCREMENT,
    user_id          BIGINT       NULL,
    education_level  VARCHAR(100) NULL,
    institution_name VARCHAR(255) NULL,
    field_of_study   VARCHAR(255) NULL,
    country          VARCHAR(100) NULL,
    province         VARCHAR(100) NULL,
    academic_results VARCHAR(500) NULL,
    biography        TEXT         NULL,
    PRIMARY KEY (profile_id),
    CONSTRAINT fk_applicant_profile_user FOREIGN KEY (user_id) REFERENCES users (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS opportunities (
    opportunity_id   BIGINT       NOT NULL AUTO_INCREMENT,
    provider_user_id BIGINT       NULL,
    title            VARCHAR(255) NULL,
    description      TEXT         NULL,
    provider_name    VARCHAR(255) NULL,
    education_level  VARCHAR(100) NULL,
    funding_type     VARCHAR(100) NULL,
    country          VARCHAR(100) NULL,
    deadline         DATE         NULL,
    status           VARCHAR(50)  NULL,
    created_at       DATETIME(6)  NULL,
    target_field     VARCHAR(255) NULL,
    target_country   VARCHAR(255) NULL,
    PRIMARY KEY (opportunity_id),
    CONSTRAINT fk_opportunity_provider FOREIGN KEY (provider_user_id) REFERENCES users (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS applications (
    application_id     BIGINT       NOT NULL AUTO_INCREMENT,
    user_id            BIGINT       NULL,
    opportunity_id     BIGINT       NULL,
    application_status VARCHAR(50)  NULL,
    submitted_at       DATETIME(6)  NULL,
    personal_statement TEXT         NULL,
    document_filename  VARCHAR(255) NULL,
    document_path      VARCHAR(255) NULL,
    rejection_reason   VARCHAR(500) NULL,
    PRIMARY KEY (application_id),
    UNIQUE KEY uk_applications_user_opportunity (user_id, opportunity_id),
    CONSTRAINT fk_application_user FOREIGN KEY (user_id) REFERENCES users (user_id),
    CONSTRAINT fk_application_opportunity FOREIGN KEY (opportunity_id) REFERENCES opportunities (opportunity_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
    notification_id BIGINT       NOT NULL AUTO_INCREMENT,
    user_id         BIGINT       NULL,
    type            VARCHAR(50)  NULL,
    message         VARCHAR(500) NULL,
    link            VARCHAR(255) NULL,
    related_id      BIGINT       NULL,
    is_read         BIT(1)       NOT NULL DEFAULT 0,
    created_at      DATETIME(6)  NULL,
    PRIMARY KEY (notification_id),
    CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
    audit_id     BIGINT       NOT NULL AUTO_INCREMENT,
    actor_email  VARCHAR(255) NOT NULL,
    action       VARCHAR(50)  NOT NULL,
    entity_type  VARCHAR(50)  NOT NULL,
    entity_id    BIGINT       NULL,
    details      TEXT         NULL,
    created_at   DATETIME(6)  NULL,
    PRIMARY KEY (audit_id)
) ENGINE=InnoDB;
