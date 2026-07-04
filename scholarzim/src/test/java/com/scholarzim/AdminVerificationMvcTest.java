package com.scholarzim;

import com.scholarzim.entity.User;
import com.scholarzim.support.MvcIntegrationTestBase;
import com.scholarzim.support.MvcTestSupport;
import org.junit.jupiter.api.Test;

import java.util.UUID;

import static org.hamcrest.Matchers.containsString;
import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.springframework.security.test.web.servlet.request.SecurityMockMvcRequestPostProcessors.csrf;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.content;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.flash;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.header;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.redirectedUrl;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.view;

class AdminVerificationMvcTest extends MvcIntegrationTestBase {

    @Test
    void adminDashboardLoadsWithPendingProviders() throws Exception {
        User pending = data.savePendingProviderWithProfile("pending-" + UUID.randomUUID() + "@org.co.zw");

        mockMvc.perform(get("/admin/dashboard").with(MvcTestSupport.asAdmin("admin@test.com")))
                .andExpect(status().isOk())
                .andExpect(view().name("admin/dashboard"))
                .andExpect(content().string(containsString(pending.getFullName())));
    }

    @Test
    void adminCanApproveProviderWithCertificate() throws Exception {
        User pending = data.savePendingProviderWithProfile("approve-" + UUID.randomUUID() + "@org.co.zw");

        mockMvc.perform(post("/admin/users/providers/{id}/approve", pending.getUserId())
                        .with(csrf())
                        .with(MvcTestSupport.asAdmin("admin@test.com")))
                .andExpect(status().is3xxRedirection())
                .andExpect(redirectedUrl("/admin/dashboard#user-management"))
                .andExpect(flash().attribute("successMessage", "Provider approved."));

        User reloaded = userRepository.findById(pending.getUserId()).orElseThrow();
        assertEquals("ACTIVE", reloaded.getAccountStatus());
    }

    @Test
    void adminCanRejectPendingProvider() throws Exception {
        User pending = data.savePendingProviderWithProfile("reject-" + UUID.randomUUID() + "@org.co.zw");

        mockMvc.perform(post("/admin/users/providers/{id}/reject", pending.getUserId())
                        .param("reason", "Incomplete documentation")
                        .with(csrf())
                        .with(MvcTestSupport.asAdmin("admin@test.com")))
                .andExpect(status().is3xxRedirection())
                .andExpect(redirectedUrl("/admin/dashboard#user-management"))
                .andExpect(flash().attribute("successMessage", "Provider application rejected."));

        User reloaded = userRepository.findById(pending.getUserId()).orElseThrow();
        assertEquals("REJECTED", reloaded.getAccountStatus());
    }

    @Test
    void adminCanDownloadProviderCertificate() throws Exception {
        User pending = data.savePendingProviderWithProfile("cert-" + UUID.randomUUID() + "@org.co.zw");

        mockMvc.perform(get("/admin/providers/{userId}/certificate", pending.getUserId())
                        .with(MvcTestSupport.asAdmin("admin@test.com")))
                .andExpect(status().isOk())
                .andExpect(header().string("Content-Type", containsString("application/pdf")))
                .andExpect(header().string("Content-Disposition", containsString("inline")));
    }

    @Test
    void providerCannotOpenAdminDashboard() throws Exception {
        mockMvc.perform(get("/admin/dashboard").with(MvcTestSupport.asProvider("prov@test.com")))
                .andExpect(status().isForbidden());
    }

    @Test
    void providerCannotDownloadCertificateViaAdminEndpoint() throws Exception {
        User pending = data.savePendingProviderWithProfile("blocked-" + UUID.randomUUID() + "@org.co.zw");

        mockMvc.perform(get("/admin/providers/{userId}/certificate", pending.getUserId())
                        .with(MvcTestSupport.asProvider("other-prov@test.com")))
                .andExpect(status().isForbidden());
    }
}
