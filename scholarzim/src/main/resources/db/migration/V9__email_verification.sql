ALTER TABLE users ADD COLUMN email_verified BOOLEAN NOT NULL DEFAULT TRUE;

UPDATE users SET email_verified = TRUE WHERE email_verified IS NULL;

CREATE TABLE email_verification_tokens (
    token_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used BOOLEAN NOT NULL DEFAULT FALSE,
    CONSTRAINT fk_email_verification_user FOREIGN KEY (user_id) REFERENCES users(user_id),
    CONSTRAINT uq_email_verification_token UNIQUE (token)
);

CREATE INDEX idx_email_verification_user ON email_verification_tokens(user_id);
