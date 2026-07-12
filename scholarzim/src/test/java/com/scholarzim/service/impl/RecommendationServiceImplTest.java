package com.scholarzim.service.impl;

import com.scholarzim.entity.ApplicantProfile;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.service.scholarfit.ScholarFitEngine;
import org.junit.jupiter.api.Test;

import java.time.LocalDate;

import static org.assertj.core.api.Assertions.assertThat;

class RecommendationServiceImplTest {

    @Test
    void scholarFitScoresHighlyForAlignedProfile() {
        ScholarFitEngine engine = new ScholarFitEngine();

        ApplicantProfile profile = new ApplicantProfile();
        profile.setEducationLevel("Undergraduate");
        profile.setFieldOfStudy("Computer Science");
        profile.setCountry("Zimbabwe");
        profile.setProvince("Harare");
        profile.setAcademicResults("GPA 3.5 with distinction in core subjects");
        profile.setResultsCertificatePath("/uploads/cert.pdf");

        Opportunity opportunity = new Opportunity();
        opportunity.setEducationLevel("Undergraduate");
        opportunity.setTargetField("Computer Science");
        opportunity.setTargetCountry("Zimbabwe");
        opportunity.setDeadline(LocalDate.now().plusDays(7));

        var scored = engine.evaluate(profile, opportunity);

        assertThat(scored.getMatchScore()).isGreaterThanOrEqualTo(70);
        assertThat(scored.getBreakdown().getFieldScore()).isGreaterThanOrEqualTo(15);
        assertThat(scored.getBreakdown().getReasons()).isNotEmpty();
    }
}
