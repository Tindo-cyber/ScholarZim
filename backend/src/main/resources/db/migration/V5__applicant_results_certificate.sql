ALTER TABLE applicant_profiles
    ADD COLUMN results_certificate_path     VARCHAR(255) NULL,
    ADD COLUMN results_certificate_filename VARCHAR(255) NULL,
    ADD COLUMN results_uploaded_at          DATETIME(6) NULL;
