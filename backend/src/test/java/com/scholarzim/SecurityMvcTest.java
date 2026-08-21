package com.scholarzim;

import com.scholarzim.entity.Application;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.User;
import com.scholarzim.support.MvcIntegrationTestBase;
import com.scholarzim.support.MvcTestSupport;
import org.junit.jupiter.api.Test;
import org.springframework.security.test.context.support.WithMockUser;

import java.util.UUID;

import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.redirectedUrlPattern;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.view;

class SecurityMvcTest extends MvcIntegrationTestBase {

    @Test
    void publicScholarshipsPageIsAccessible() throws Exception {
        mockMvc.perform(get("/scholarships"))
                .andExpect(status().isOk())
                .andExpect(view().name("public/scholarships"));
    }

    @Test
    void applicantDashboardRequiresAuthentication() throws Exception {
        mockMvc.perform(get("/applicant/dashboard"))
                .andExpect(status().is3xxRedirection())
                .andExpect(redirectedUrlPattern("**/login**"));
    }

    @Test
    @WithMockUser(roles = "APPLICANT")
    void authenticatedApplicantCanOpenDashboard() throws Exception {
        mockMvc.perform(get("/applicant/dashboard"))
                .andExpect(status().isOk())
                .andExpect(view().name("applicant/dashboard"));
    }

    @Test
    @WithMockUser(roles = "PROVIDER")
    void providerCannotOpenApplicantDashboard() throws Exception {
        mockMvc.perform(get("/applicant/dashboard"))
                .andExpect(status().isForbidden());
    }

    @Test
    void legacyUploadsPathIsNotPublic() throws Exception {
        mockMvc.perform(get("/uploads/test.pdf"))
                .andExpect(status().is3xxRedirection())
                .andExpect(redirectedUrlPattern("**/login**"));
    }

    @Test
    @WithMockUser(username = "user@test.com")
    void securedDocumentEndpointRequiresExistingApplication() throws Exception {
        mockMvc.perform(get("/applications/99999/document"))
                .andExpect(status().isNotFound());
    }

    @Test
    void resultsCertificateRequiresAuthentication() throws Exception {
        mockMvc.perform(get("/applications/1/results-certificate"))
                .andExpect(status().is3xxRedirection())
                .andExpect(redirectedUrlPattern("**/login**"));
    }

    @Test
    void unrelatedProviderCannotDownloadResultsCertificate() throws Exception {
        String applicantEmail = "sec-app-" + UUID.randomUUID() + "@student.co.zw";
        String ownerEmail = "sec-owner-" + UUID.randomUUID() + "@org.co.zw";
        String strangerEmail = "sec-stranger-" + UUID.randomUUID() + "@org.co.zw";

        User applicant = data.saveApplicantWithResultsCertificate(applicantEmail).getUser();
        User owner = data.saveProvider(ownerEmail);
        data.saveProvider(strangerEmail);
        Opportunity opportunity = data.saveOpportunity(owner);
        Application application = data.saveApplication(applicant, opportunity);

        mockMvc.perform(get("/applications/{id}/results-certificate", application.getApplicationId())
                        .with(MvcTestSupport.asProvider(strangerEmail)))
                .andExpect(status().isForbidden());
    }
}
