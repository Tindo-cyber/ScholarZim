package com.scholarzim;

import com.scholarzim.support.MvcIntegrationTestBase;
import com.scholarzim.support.MvcTestSupport;
import com.scholarzim.entity.ApplicantProfile;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.User;
import org.junit.jupiter.api.Test;
import org.springframework.security.test.context.support.WithMockUser;

import java.util.UUID;

import static org.hamcrest.Matchers.containsString;
import static org.hamcrest.Matchers.not;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.content;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.view;

class ApplicantDashboardMvcTest extends MvcIntegrationTestBase {

    @Test
    @WithMockUser(roles = "APPLICANT")
    void dashboardRendersPremiumLayout() throws Exception {
        String email = "dash-" + UUID.randomUUID() + "@student.co.zw";
        ApplicantProfile profile = data.saveApplicantWithResultsCertificate(email);
        User applicant = profile.getUser();
        User provider = data.saveProvider("provider-" + UUID.randomUUID() + "@test.com");
        Opportunity opportunity = data.saveOpportunity(provider);
        data.saveApplication(applicant, opportunity);

        mockMvc.perform(get("/applicant/dashboard").with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(view().name("applicant/dashboard"))
                .andExpect(content().string(containsString("sz-applicant-dashboard")))
                .andExpect(content().string(containsString("ScholarFit AI Matches")))
                .andExpect(content().string(containsString("Application Timeline")))
                .andExpect(content().string(containsString("Upcoming Deadlines Calendar")))
                .andExpect(content().string(not(containsString("sz-landing-header"))))
                .andExpect(content().string(containsString("sz-sidebar")));
    }
}
