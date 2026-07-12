ALTER TABLE applicant_profiles
    ADD COLUMN cv_path                      VARCHAR(255) NULL,
    ADD COLUMN cv_filename                  VARCHAR(255) NULL,
    ADD COLUMN cv_uploaded_at               DATETIME(6) NULL,
    ADD COLUMN passport_path                VARCHAR(255) NULL,
    ADD COLUMN passport_filename            VARCHAR(255) NULL,
    ADD COLUMN passport_uploaded_at         DATETIME(6) NULL,
    ADD COLUMN recommendation_letter_path   VARCHAR(255) NULL,
    ADD COLUMN recommendation_letter_filename VARCHAR(255) NULL,
    ADD COLUMN recommendation_letter_uploaded_at DATETIME(6) NULL;
