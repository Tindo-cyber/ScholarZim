package com.scholarzim;

import com.scholarzim.support.MvcIntegrationTestBase;
import com.scholarzim.support.MvcTestSupport;
import org.junit.jupiter.api.Test;

import java.util.UUID;

import static org.hamcrest.Matchers.containsString;
import static org.hamcrest.Matchers.not;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.content;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

class PolishUxMvcTest extends MvcIntegrationTestBase {

    @Test
    void dashboardShellIncludesUxPrimitives() throws Exception {
        String email = "polish-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicant(email);

        mockMvc.perform(get("/applicant/profile").with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("sz-toast-container")))
                .andExpect(content().string(containsString("szConfirmModal")))
                .andExpect(content().string(containsString("sz-breadcrumbs")))
                .andExpect(content().string(containsString("polish.css")))
                .andExpect(content().string(containsString("app.js?v=58")));
    }

    @Test
    void adminSearchRendersWithoutEmptyStateWidget() throws Exception {
        mockMvc.perform(get("/admin/search").with(MvcTestSupport.asAdmin("admin@test.com")))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("Search")))
                .andExpect(content().string(not(containsString("Search the platform"))));
    }
}
