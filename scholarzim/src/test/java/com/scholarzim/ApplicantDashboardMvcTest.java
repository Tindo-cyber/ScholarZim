package com.scholarzim;

import com.scholarzim.support.MvcIntegrationTestBase;
import com.scholarzim.support.MvcTestSupport;
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
        data.saveApplicant(email);

        mockMvc.perform(get("/applicant/dashboard").with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(view().name("applicant/dashboard"))
                .andExpect(content().string(containsString("sz-applicant-dashboard")))
                .andExpect(content().string(containsString("AI Recommended Scholarships")))
                .andExpect(content().string(containsString("Application Timeline")))
                .andExpect(content().string(containsString("Upcoming Deadlines Calendar")))
                .andExpect(content().string(not(containsString("sz-landing-header"))))
                .andExpect(content().string(containsString("sz-sidebar")));
    }
}
