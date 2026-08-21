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

class NavigationMvcTest extends MvcIntegrationTestBase {

    @Test
    @WithMockUser(roles = "APPLICANT")
    void applicantDashboardRendersPremiumNavigation() throws Exception {
        String email = "nav-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicant(email);

        mockMvc.perform(get("/applicant/dashboard").with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("szShell")))
                .andExpect(content().string(containsString("data-sidebar-collapse")))
                .andExpect(content().string(containsString("sz-nav-link")))
                .andExpect(content().string(containsString("szGlobalSearch")))
                .andExpect(content().string(containsString("sz-quick-actions-menu")))
                .andExpect(content().string(containsString("sz-user-avatar")))
                .andExpect(content().string(containsString("themeToggle")))
                .andExpect(content().string(containsString("szShortcutsModal")));
    }

    @Test
    @WithMockUser(roles = "ADMIN")
    void adminTopbarRendersGlobalSearch() throws Exception {
        mockMvc.perform(get("/admin/dashboard"))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("Search users, scholarships, applications")))
                .andExpect(content().string(containsString("Global search")));
    }
}
