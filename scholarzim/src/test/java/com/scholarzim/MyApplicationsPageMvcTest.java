package com.scholarzim;

import com.scholarzim.support.MvcIntegrationTestBase;
import com.scholarzim.support.MvcTestSupport;
import org.junit.jupiter.api.Test;
import org.springframework.security.test.context.support.WithMockUser;
import java.util.UUID;
import static org.hamcrest.Matchers.*;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.*;

class MyApplicationsPageMvcTest extends MvcIntegrationTestBase {
    @Test
    @WithMockUser(roles = "APPLICANT")
    void myApplicationsPageRendersTracker() throws Exception {
        String email = "apps-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicant(email);
        mockMvc.perform(get("/my-applications").with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(view().name("applications/my-applications"))
                .andExpect(content().string(containsString("Application tracker")))
                .andExpect(content().string(containsString("No applications found.")))
                .andExpect(content().string(containsString("You have not submitted any scholarship applications yet.")))
                .andExpect(content().string(not(containsString("Funding for every stage"))))
                .andExpect(content().string(not(containsString("Something went wrong"))))
                .andExpect(content().string(not(containsString("sz-landing-announce"))))
                .andExpect(content().string(not(containsString("Become a provider"))));
    }

    @Test
    @WithMockUser(roles = "APPLICANT")
    void myApplicationsPageRendersWithExistingApplications() throws Exception {
        String email = "apps-list-" + UUID.randomUUID() + "@student.co.zw";
        var applicant = data.saveApplicant(email);
        var provider = data.saveProvider("prov-" + UUID.randomUUID() + "@org.co.zw");
        var opportunity = data.saveOpportunity(provider);
        data.saveApplication(applicant, opportunity);

        mockMvc.perform(get("/my-applications").with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(view().name("applications/my-applications"))
                .andExpect(content().string(containsString("Test Scholarship")))
                .andExpect(content().string(containsString("sz-pro-table")))
                .andExpect(content().string(not(containsString("Something went wrong"))))
                .andExpect(content().string(not(containsString("sz-landing-header"))))
                .andExpect(content().string(containsString("sz-sidebar")));
    }
}
