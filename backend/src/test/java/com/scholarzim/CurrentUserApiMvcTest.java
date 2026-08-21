package com.scholarzim;

import com.scholarzim.support.MvcIntegrationTestBase;
import com.scholarzim.support.MvcTestSupport;
import org.junit.jupiter.api.Test;
import org.springframework.http.MediaType;
import org.springframework.security.test.context.support.WithMockUser;

import java.util.UUID;

import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.jsonPath;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

class CurrentUserApiMvcTest extends MvcIntegrationTestBase {

    /**
     * The React app calls /api/me on every load, including when signed out —
     * so anonymous access must answer 200 with authenticated:false rather than
     * redirecting to the login page.
     */
    @Test
    void anonymousGetsAuthenticatedFalseInsteadOfRedirect() throws Exception {
        mockMvc.perform(get("/api/me").accept(MediaType.APPLICATION_JSON))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.authenticated").value(false))
                .andExpect(jsonPath("$.role").doesNotExist());
    }

    @Test
    @WithMockUser(roles = "APPLICANT")
    void signedInApplicantGetsIdentityAndRole() throws Exception {
        String email = "api-me-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicant(email);

        mockMvc.perform(get("/api/me")
                        .accept(MediaType.APPLICATION_JSON)
                        .with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.authenticated").value(true))
                .andExpect(jsonPath("$.email").value(email))
                .andExpect(jsonPath("$.role").value("ROLE_APPLICANT"));
    }

    /** The payload must not leak credentials to the browser. */
    @Test
    @WithMockUser(roles = "APPLICANT")
    void identityPayloadExcludesPasswordHash() throws Exception {
        String email = "api-me-safe-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicant(email);

        mockMvc.perform(get("/api/me")
                        .accept(MediaType.APPLICATION_JSON)
                        .with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.passwordHash").doesNotExist())
                .andExpect(jsonPath("$.password").doesNotExist());
    }
}
