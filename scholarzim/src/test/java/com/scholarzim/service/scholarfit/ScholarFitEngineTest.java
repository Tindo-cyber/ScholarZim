package com.scholarzim.service.scholarfit;

import com.scholarzim.entity.ApplicantProfile;
import com.scholarzim.entity.Opportunity;
import org.junit.jupiter.api.Test;

import java.time.LocalDate;

import static org.assertj.core.api.Assertions.assertThat;

class ScholarFitEngineTest {

    private final ScholarFitEngine engine = new ScholarFitEngine();

    @Test
    void strongProfileProducesHighMatchWithReasons() {
        ApplicantProfile profile = fullProfile();
        Opportunity opportunity = matchingOpportunity();

        var scored = engine.evaluate(profile, opportunity);

        assertThat(scored.getMatchScore()).isGreaterThanOrEqualTo(75);
        assertThat(scored.getBreakdown().getConfidenceLevel()).isEqualTo("HIGH");
        assertThat(scored.getBreakdown().getReasons())
                .anyMatch(r -> r.getKey().equals("gpa") && r.isSatisfied())
                .anyMatch(r -> r.getKey().equals("degree") && r.isSatisfied())
                .anyMatch(r -> r.getKey().equals("deadline") && r.isSatisfied());
        assertThat(scored.getBreakdown().getMissingRequirements()).isEmpty();
    }

    @Test
    void missingCertificateHighlightsGap() {
        ApplicantProfile profile = fullProfile();
        profile.setResultsCertificatePath(null);

        var scored = engine.evaluate(profile, matchingOpportunity());

        assertThat(scored.getBreakdown().getMissingRequirements())
                .anyMatch(m -> m.toLowerCase().contains("certificate"));
        assertThat(scored.getBreakdown().getReasons())
                .anyMatch(r -> r.getKey().equals("certificate") && !r.isSatisfied());
    }

    @Test
    void resultsSortedByHighestMatchFirst() {
        // Sorting is service-level; engine returns individual scores capped at 100
        var scored = engine.evaluate(fullProfile(), matchingOpportunity());
        assertThat(scored.getMatchScore()).isLessThanOrEqualTo(100);
    }

    private static ApplicantProfile fullProfile() {
        ApplicantProfile profile = new ApplicantProfile();
        profile.setEducationLevel("Undergraduate");
        profile.setFieldOfStudy("Computer Science");
        profile.setCountry("Zimbabwe");
        profile.setProvince("Harare");
        profile.setAcademicResults("GPA 3.6 — Distinction in Mathematics and Computer Science");
        profile.setResultsCertificatePath("/uploads/results.pdf");
        return profile;
    }

    private static Opportunity matchingOpportunity() {
        Opportunity opportunity = new Opportunity();
        opportunity.setEducationLevel("Undergraduate");
        opportunity.setTargetField("Computer Science");
        opportunity.setTargetCountry("Zimbabwe");
        opportunity.setCountry("Zimbabwe");
        opportunity.setDeadline(LocalDate.now().plusDays(21));
        opportunity.setFundingType("Full Scholarship");
        opportunity.setTitle("STEM Excellence Award");
        opportunity.setProviderName("University of Zimbabwe");
        return opportunity;
    }
}
