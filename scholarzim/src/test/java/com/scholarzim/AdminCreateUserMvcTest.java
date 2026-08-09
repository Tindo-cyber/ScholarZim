package com.scholarzim;

import com.scholarzim.entity.User;
import com.scholarzim.support.MvcIntegrationTestBase;
import com.scholarzim.support.MvcTestSupport;
import org.junit.jupiter.api.Test;

import java.util.UUID;

import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertNull;
import static org.junit.jupiter.api.Assertions.assertTrue;
import static org.springframework.security.test.web.servlet.request.SecurityMockMvcRequestPostProcessors.csrf;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.redirectedUrlPattern;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

class AdminCreateUserMvcTest extends MvcIntegrationTestBase {

    @Test
    void regularAdminCannotCreateAdminAccount() throws Exception {
        String email = "new-admin-" + UUID.randomUUID() + "@scholarzim.co.zw";

        mockMvc.perform(post("/admin/users/create")
                        .param("fullName", "Would-be Admin")
                        .param("email", email)
                        .param("role", "ROLE_ADMIN")
                        .with(csrf())
                        .with(MvcTestSupport.asAdmin("regular-admin@test.com")))
                .andExpect(status().isForbidden());

        assertTrue(userRepository.findByEmail(email).isEmpty());
    }

    @Test
    void superAdminCanCreateAdminAccountWithoutAssigningPassword() throws Exception {
        String email = "new-admin-" + UUID.randomUUID() + "@scholarzim.co.zw";

        mockMvc.perform(post("/admin/users/create")
                        .param("fullName", "New Admin")
                        .param("email", email)
                        .param("role", "ROLE_ADMIN")
                        .with(csrf())
                        .with(MvcTestSupport.asSuperAdmin("super-admin@test.com")))
                .andExpect(status().is3xxRedirection())
                .andExpect(redirectedUrlPattern("/admin/dashboard*"));

        User created = userRepository.findByEmail(email).orElseThrow();
        assertNull(created.getPasswordHash());
        assertFalse(created.isEmailVerified());
        assertTrue("ROLE_ADMIN".equals(created.getRole().getRoleName()));
    }

    @Test
    void regularAdminCanCreateApplicantAccount() throws Exception {
        String email = "new-applicant-" + UUID.randomUUID() + "@student.co.zw";

        mockMvc.perform(post("/admin/users/create")
                        .param("fullName", "New Applicant")
                        .param("email", email)
                        .param("role", "ROLE_APPLICANT")
                        .param("password", "Password123!")
                        .param("confirmPassword", "Password123!")
                        .with(csrf())
                        .with(MvcTestSupport.asAdmin("regular-admin2@test.com")))
                .andExpect(status().is3xxRedirection())
                .andExpect(redirectedUrlPattern("/admin/dashboard*"));

        User created = userRepository.findByEmail(email).orElseThrow();
        assertFalse(created.isEmailVerified());
        assertTrue(created.getPasswordHash() != null && !created.getPasswordHash().isBlank());
    }

    @Test
    void adminCreatedAccountCannotLoginBeforeEmailConfirmed() throws Exception {
        String email = "unconfirmed-" + UUID.randomUUID() + "@student.co.zw";

        mockMvc.perform(post("/admin/users/create")
                        .param("fullName", "Unconfirmed Applicant")
                        .param("email", email)
                        .param("role", "ROLE_APPLICANT")
                        .param("password", "Password123!")
                        .param("confirmPassword", "Password123!")
                        .with(csrf())
                        .with(MvcTestSupport.asAdmin("regular-admin3@test.com")))
                .andExpect(status().is3xxRedirection());

        mockMvc.perform(post("/login")
                        .param("username", email)
                        .param("password", "Password123!")
                        .with(csrf()))
                .andExpect(status().is3xxRedirection())
                .andExpect(redirectedUrlPattern("/login?error*"));
    }
}
