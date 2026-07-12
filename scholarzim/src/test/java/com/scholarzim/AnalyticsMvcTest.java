package com.scholarzim;

import com.scholarzim.support.MvcIntegrationTestBase;
import com.scholarzim.support.MvcTestSupport;
import org.junit.jupiter.api.Test;

import static org.hamcrest.Matchers.containsString;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.content;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.view;

class AnalyticsMvcTest extends MvcIntegrationTestBase {

    @Test
    void adminAnalyticsDashboardRendersWidgets() throws Exception {
        mockMvc.perform(get("/admin/analytics").with(MvcTestSupport.asAdmin("admin@test.com")))
                .andExpect(status().isOk())
                .andExpect(view().name("admin/analytics"))
                .andExpect(content().string(containsString("sz-analytics-hub")))
                .andExpect(content().string(containsString("Analytics dashboard")))
                .andExpect(content().string(containsString("Monthly platform growth")))
                .andExpect(content().string(containsString("Application trends")))
                .andExpect(content().string(containsString("Pending approvals")))
                .andExpect(content().string(containsString("Most viewed scholarships")))
                .andExpect(content().string(containsString("Recent users")))
                .andExpect(content().string(containsString("growthChart")))
                .andExpect(content().string(containsString("Coming soon")));
    }

    @Test
    void providerCannotOpenAnalytics() throws Exception {
        mockMvc.perform(get("/admin/analytics").with(MvcTestSupport.asProvider("prov@test.com")))
                .andExpect(status().isForbidden());
    }
}
