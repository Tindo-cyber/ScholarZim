-- Additional indexes for provider dashboards, applicant lookups, and saved scholarships.

CREATE INDEX idx_opportunities_provider_id
    ON opportunities (provider_id);

CREATE INDEX idx_applications_user_id
    ON applications (user_id);

CREATE INDEX idx_applications_opportunity_id
    ON applications (opportunity_id);

CREATE INDEX idx_saved_scholarships_user_id
    ON saved_scholarships (user_id);

CREATE INDEX idx_opportunities_provider_name
    ON opportunities (provider_name);
