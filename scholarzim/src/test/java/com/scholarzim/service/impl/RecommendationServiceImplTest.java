package com.scholarzim.service.impl;

import com.scholarzim.dto.MatchBreakdownDTO;
import com.scholarzim.entity.ApplicantProfile;
import com.scholarzim.entity.Opportunity;
import org.junit.jupiter.api.Test;

import java.lang.reflect.Method;
import java.time.LocalDate;

import static org.junit.jupiter.api.Assertions.assertTrue;

class RecommendationServiceImplTest {

    @Test
    void exactFieldMatchScoresHighly() throws Exception {

        RecommendationServiceImpl service = new RecommendationServiceImpl(null, null, null);

        ApplicantProfile profile = new ApplicantProfile();
        profile.setEducationLevel("Undergraduate");
        profile.setFieldOfStudy("Computer Science");
        profile.setCountry("Zimbabwe");
        profile.setProvince("Harare");

        Opportunity opportunity = new Opportunity();
        opportunity.setEducationLevel("Undergraduate");
        opportunity.setTargetField("Computer Science");
        opportunity.setTargetCountry("Zimbabwe");
        opportunity.setDeadline(LocalDate.now().plusDays(7));
        opportunity.setStatus("ACTIVE");

        Method scoreMethod = RecommendationServiceImpl.class.getDeclaredMethod(
                "score", ApplicantProfile.class, Opportunity.class);
        scoreMethod.setAccessible(true);

        var scored = (com.scholarzim.dto.ScoredOpportunityDTO) scoreMethod.invoke(service, profile, opportunity);

        assertTrue(scored.getMatchScore() >= 70);
        MatchBreakdownDTO breakdown = scored.getBreakdown();
        assertTrue(breakdown.getFieldScore() >= 35);
    }
}
