package com.scholarzim;

import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.User;
import com.scholarzim.support.MvcIntegrationTestBase;
import com.scholarzim.support.MvcTestSupport;
import org.junit.jupiter.api.Test;
import org.springframework.security.test.context.support.WithMockUser;

import java.util.UUID;

import static org.hamcrest.Matchers.containsString;
import static org.springframework.security.test.web.servlet.request.SecurityMockMvcRequestPostProcessors.csrf;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.flash;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.redirectedUrl;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.redirectedUrlPattern;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

class ApplicationFlowMvcTest extends MvcIntegrationTestBase {

    private static final String STATEMENT =
            "I am passionate about education and committed to contributing to my community through this scholarship.";

    @Test
    @WithMockUser(roles = "APPLICANT")
    void quickApplyWithoutCertificateRedirectsWithError() throws Exception {
        String email = "quick-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicant(email);
        User provider = data.saveProvider("prov-" + UUID.randomUUID() + "@org.co.zw");
        Opportunity opportunity = data.saveOpportunity(provider);

        mockMvc.perform(post("/apply/{id}/quick", opportunity.getOpportunityId())
                        .with(csrf())
                        .with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().is3xxRedirection())
                .andExpect(redirectedUrl("/opportunities"))
                .andExpect(flash().attribute("errorMessage",
                        containsString("results certificate")));
    }

    @Test
    @WithMockUser(roles = "APPLICANT")
    void wizardSubmitWithCertificateRedirectsToConfirmation() throws Exception {
        String email = "wizard-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicantWithResultsCertificate(email);
        User provider = data.saveProvider("prov-" + UUID.randomUUID() + "@org.co.zw");
        Opportunity opportunity = data.saveOpportunity(provider);

        mockMvc.perform(post("/apply/{id}", opportunity.getOpportunityId())
                        .param("personalStatement", STATEMENT)
                        .with(csrf())
                        .with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().is3xxRedirection())
                .andExpect(redirectedUrlPattern("/applications/*/confirmation"));
    }

    @Test
    @WithMockUser(roles = "APPLICANT")
    void duplicateApplyRedirectsWithError() throws Exception {
        String email = "dup-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicantWithResultsCertificate(email);
        User provider = data.saveProvider("prov-" + UUID.randomUUID() + "@org.co.zw");
        Opportunity opportunity = data.saveOpportunity(provider);

        mockMvc.perform(post("/apply/{id}/quick", opportunity.getOpportunityId())
                        .with(csrf())
                        .with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().is3xxRedirection())
                .andExpect(redirectedUrl("/opportunities"))
                .andExpect(flash().attribute("successMessage", "Application submitted."));

        mockMvc.perform(post("/apply/{id}/quick", opportunity.getOpportunityId())
                        .with(csrf())
                        .with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().is3xxRedirection())
                .andExpect(redirectedUrl("/opportunities"))
                .andExpect(flash().attribute("errorMessage",
                        "You have already applied to this opportunity."));
    }
}
