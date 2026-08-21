package com.scholarzim;

import com.scholarzim.entity.ProviderProfile;
import com.scholarzim.entity.Role;
import com.scholarzim.entity.User;
import com.scholarzim.support.MvcIntegrationTestBase;
import com.scholarzim.support.TestDataFactory;
import com.scholarzim.util.ProviderOrgType;
import org.junit.jupiter.api.Test;
import org.springframework.security.test.context.support.WithMockUser;

import java.util.UUID;

import static org.hamcrest.Matchers.containsString;
import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;
import static org.springframework.security.test.web.servlet.request.SecurityMockMvcRequestPostProcessors.csrf;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.multipart;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.header;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.redirectedUrl;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.redirectedUrlPattern;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

class ProviderRegistrationMvcTest extends MvcIntegrationTestBase {

    private Role ensureProviderRole() {
        return roleRepository.findByRoleName("ROLE_PROVIDER").orElseGet(() -> {
            Role role = new Role();
            role.setRoleName("ROLE_PROVIDER");
            role.setDescription("Scholarship provider");
            return roleRepository.save(role);
        });
    }

    @Test
    void providerRegistrationRequiresCertificate() throws Exception {
        String email = "pending-" + UUID.randomUUID() + "@org.co.zw";

        mockMvc.perform(multipart("/register/provider")
                        .param("fullName", "Test Foundation")
                        .param("organisationType", ProviderOrgType.NGO)
                        .param("registrationNumber", "NGO-123/2024")
                        .param("email", email)
                        .param("phone", "+263 77 000 0000")
                        .param("password", "Password123!")
                        .param("confirmPassword", "Password123!")
                        .with(csrf()))
                .andExpect(status().isOk());

        assertTrue(userRepository.findByEmail(email).isEmpty());
    }

    @Test
    void providerRegistrationWithCertificateCreatesPendingProfile() throws Exception {
        String email = "provider-" + UUID.randomUUID() + "@org.co.zw";

        mockMvc.perform(multipart("/register/provider")
                        .file(TestDataFactory.providerCertificatePdf())
                        .param("fullName", "Verified Org")
                        .param("organisationType", ProviderOrgType.PRIVATE_COMPANY)
                        .param("registrationNumber", "12345/2020")
                        .param("email", email)
                        .param("phone", "+263 77 111 2222")
                        .param("password", "Password123!")
                        .param("confirmPassword", "Password123!")
                        .with(csrf()))
                .andExpect(status().is3xxRedirection())
                .andExpect(redirectedUrl("/login?pending=1"));

        User user = userRepository.findByEmail(email).orElseThrow();
        assertEquals("PENDING_APPROVAL", user.getAccountStatus());

        ProviderProfile profile = providerProfileRepository.findByUser(user).orElseThrow();
        assertEquals(ProviderOrgType.PRIVATE_COMPANY, profile.getOrganisationType());
        assertEquals("12345/2020", profile.getRegistrationNumber());
        assertTrue(profile.getCertificatePath() != null && !profile.getCertificatePath().isBlank());
    }

    @Test
    @WithMockUser(roles = "ADMIN")
    void adminCanDownloadProviderCertificate() throws Exception {
        User user = data.savePendingProviderWithProfile("cert-" + UUID.randomUUID() + "@org.co.zw");

        mockMvc.perform(get("/admin/providers/{userId}/certificate", user.getUserId()))
                .andExpect(status().isOk())
                .andExpect(header().string("Content-Type", containsString("application/pdf")));
    }

    @Test
    void nonAdminCannotDownloadProviderCertificate() throws Exception {
        User user = data.savePendingProviderWithProfile("private-" + UUID.randomUUID() + "@org.co.zw");

        mockMvc.perform(get("/admin/providers/{userId}/certificate", user.getUserId()))
                .andExpect(status().is3xxRedirection())
                .andExpect(redirectedUrlPattern("**/login**"));
    }

    @Test
    @WithMockUser(username = "admin@test.com", roles = "ADMIN")
    void approveProviderWithoutProfileFails() throws Exception {
        User pending = new User();
        pending.setFullName("No Docs Org");
        pending.setEmail("nodocs-" + UUID.randomUUID() + "@org.co.zw");
        pending.setPasswordHash("hash");
        pending.setAccountStatus("PENDING_APPROVAL");
        pending.setRole(ensureProviderRole());
        pending = userRepository.save(pending);

        mockMvc.perform(post("/admin/users/providers/{id}/approve", pending.getUserId()).with(csrf()))
                .andExpect(status().is3xxRedirection())
                .andExpect(redirectedUrl("/admin/dashboard#user-management"));

        User reloaded = userRepository.findById(pending.getUserId()).orElseThrow();
        assertEquals("PENDING_APPROVAL", reloaded.getAccountStatus());
    }
}
