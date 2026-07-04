package com.scholarzim;

import com.scholarzim.entity.Application;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.User;
import com.scholarzim.support.MvcIntegrationTestBase;
import com.scholarzim.support.MvcTestSupport;
import org.junit.jupiter.api.Test;
import org.springframework.security.test.context.support.WithMockUser;

import java.util.UUID;

import static org.hamcrest.Matchers.containsString;
import static org.junit.jupiter.api.Assertions.assertNotNull;
import static org.junit.jupiter.api.Assertions.assertTrue;
import static org.springframework.security.test.web.servlet.request.SecurityMockMvcRequestPostProcessors.csrf;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.multipart;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.content;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.header;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.redirectedUrl;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.redirectedUrlPattern;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.view;

class ApplicantResultsMvcTest extends MvcIntegrationTestBase {

    @Test
    @WithMockUser(roles = "APPLICANT")
    void profileSaveWithoutPdfWhenNoneOnFileShowsError() throws Exception {
        String email = "no-cert-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicant(email);

        mockMvc.perform(multipart("/applicant/profile")
                        .param("educationLevel", "Form 6")
                        .param("institutionName", "Prince Edward School")
                        .param("fieldOfStudy", "Sciences")
                        .param("country", "Zimbabwe")
                        .param("province", "Harare")
                        .param("academicResults", "A-Level 15 points")
                        .with(csrf())
                        .with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(view().name("applicant/profile"))
                .andExpect(content().string(containsString("Results certificate")));

        assertTrue(applicantProfileRepository.findByUser(
                userRepository.findByEmail(email).orElseThrow()).isEmpty());
    }

    @Test
    @WithMockUser(roles = "APPLICANT")
    void profileSaveWithPdfStoresCertificate() throws Exception {
        String email = "with-cert-" + UUID.randomUUID() + "@student.co.zw";
        User applicant = data.saveApplicant(email);

        mockMvc.perform(multipart("/applicant/profile")
                        .file(com.scholarzim.support.TestDataFactory.resultsPdf())
                        .param("educationLevel", "Undergraduate")
                        .param("institutionName", "University of Zimbabwe")
                        .param("fieldOfStudy", "Computer Science")
                        .param("country", "Zimbabwe")
                        .param("province", "Harare")
                        .param("academicResults", "First Class Honours")
                        .with(csrf())
                        .with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().is3xxRedirection())
                .andExpect(redirectedUrl("/applicant/dashboard"));

        var profile = applicantProfileRepository.findByUser(applicant).orElseThrow();
        assertNotNull(profile.getResultsCertificatePath());
        assertTrue(!profile.getResultsCertificatePath().isBlank());
    }

    @Test
    @WithMockUser(roles = "APPLICANT")
    void applyWizardRedirectsWhenNoCertificate() throws Exception {
        String email = "gate-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicant(email);
        User provider = data.saveProvider("prov-" + UUID.randomUUID() + "@org.co.zw");
        Opportunity opportunity = data.saveOpportunity(provider);

        mockMvc.perform(get("/apply/{id}", opportunity.getOpportunityId())
                        .with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().is3xxRedirection())
                .andExpect(redirectedUrl("/applicant/profile?resultsRequired=1"));
    }

    @Test
    @WithMockUser(roles = "APPLICANT")
    void applyWizardOpensWhenCertificateExists() throws Exception {
        String email = "ready-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicantWithResultsCertificate(email);
        User provider = data.saveProvider("prov-" + UUID.randomUUID() + "@org.co.zw");
        Opportunity opportunity = data.saveOpportunity(provider);

        mockMvc.perform(get("/apply/{id}", opportunity.getOpportunityId())
                        .with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(view().name("applications/wizard"));
    }

    @Test
    void providerCanDownloadApplicantResultsCertificate() throws Exception {
        String applicantEmail = "dl-app-" + UUID.randomUUID() + "@student.co.zw";
        String providerEmail = "dl-prov-" + UUID.randomUUID() + "@org.co.zw";
        String otherProviderEmail = "dl-other-" + UUID.randomUUID() + "@org.co.zw";

        User applicant = data.saveApplicantWithResultsCertificate(applicantEmail).getUser();
        User provider = data.saveProvider(providerEmail);
        data.saveProvider(otherProviderEmail);
        Opportunity opportunity = data.saveOpportunity(provider);
        Application application = data.saveApplication(applicant, opportunity);

        mockMvc.perform(get("/applications/{id}/results-certificate", application.getApplicationId())
                        .with(MvcTestSupport.asProvider(providerEmail)))
                .andExpect(status().isOk())
                .andExpect(header().string("Content-Type", containsString("application/pdf")));

        mockMvc.perform(get("/applications/{id}/results-certificate", application.getApplicationId())
                        .with(MvcTestSupport.asProvider(otherProviderEmail)))
                .andExpect(status().isForbidden());
    }
}
