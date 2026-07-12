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
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.forwardedUrl;

class ErrorPagesMvcTest extends MvcIntegrationTestBase {

    @Test
    void notFoundScholarshipRendersIllustratedComponent() throws Exception {
        mockMvc.perform(get("/scholarships/999999999"))
                .andExpect(status().isNotFound())
                .andExpect(content().string(containsString("sz-error-state")))
                .andExpect(content().string(containsString("Page not found")))
                .andExpect(content().string(containsString("Return home")))
                .andExpect(content().string(containsString("Contact support")))
                .andExpect(content().string(not(containsString("Exception"))))
                .andExpect(content().string(not(containsString("java."))));
    }

    @Test
    void forbiddenPageRendersPermissionDeniedComponent() throws Exception {
        mockMvc.perform(get("/403"))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("Permission denied")))
                .andExpect(content().string(containsString("sz-error-state__art--permission-denied")))
                .andExpect(content().string(containsString("Retry")))
                .andExpect(content().string(not(containsString("AccessDeniedException"))));
    }

    @Test
    @WithMockUser(roles = "APPLICANT")
    void applicantForbiddenAreaRedirectsToPermissionDeniedPage() throws Exception {
        String email = "err-applicant-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicant(email);

        mockMvc.perform(get("/admin/dashboard").with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isForbidden())
                .andExpect(forwardedUrl("/403"));

        mockMvc.perform(get("/403").with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("Permission denied")))
                .andExpect(content().string(containsString("sz-error-state__art--permission-denied")))
                .andExpect(content().string(not(containsString("AccessDeniedException"))));
    }

    @Test
    @WithMockUser(roles = "APPLICANT")
    void emptyApplicationsUsesNoDataComponent() throws Exception {
        String email = "err-empty-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicant(email);

        mockMvc.perform(get("/my-applications").with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("sz-error-state__art--no-data")))
                .andExpect(content().string(containsString("No applications found")))
                .andExpect(content().string(containsString("Return home")));
    }

    @Test
    void layoutIncludesNetworkErrorOverlay() throws Exception {
        mockMvc.perform(get("/"))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("sz-network-error")))
                .andExpect(content().string(containsString("Connection problem")));
    }
}
