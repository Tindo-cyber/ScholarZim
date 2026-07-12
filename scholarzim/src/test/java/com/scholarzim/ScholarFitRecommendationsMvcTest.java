package com.scholarzim;

import com.scholarzim.support.MvcIntegrationTestBase;
import com.scholarzim.support.MvcTestSupport;
import org.junit.jupiter.api.Test;
import org.springframework.security.test.context.support.WithMockUser;

import java.util.UUID;

import static org.hamcrest.Matchers.containsString;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.content;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.view;

class ScholarFitRecommendationsMvcTest extends MvcIntegrationTestBase {

    @Test
    @WithMockUser(roles = "APPLICANT")
    void recommendationsPageRendersScholarFitCards() throws Exception {
        String email = "scholarfit-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicantWithResultsCertificate(email);
        var provider = data.saveProvider("sf-prov-" + UUID.randomUUID() + "@org.co.zw");
        var opportunity = data.saveOpportunity(provider);
        opportunity.setTargetField("Computer Science");
        opportunity.setTargetCountry("Zimbabwe");
        opportunityRepository.save(opportunity);

        mockMvc.perform(get("/applicant/recommendations").with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(view().name("applicant/recommendations"))
                .andExpect(content().string(containsString("ScholarFit AI")))
                .andExpect(content().string(containsString("sz-scholarfit-card")))
                .andExpect(content().string(containsString("% Match")))
                .andExpect(content().string(containsString("Why this matches you")));
    }
}
