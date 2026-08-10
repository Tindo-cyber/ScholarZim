package com.scholarzim;

import com.scholarzim.entity.Application;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.User;
import com.scholarzim.support.MvcIntegrationTestBase;
import com.scholarzim.support.MvcTestSupport;
import org.junit.jupiter.api.Test;

import java.util.UUID;

import static org.hamcrest.Matchers.containsString;
import static org.hamcrest.Matchers.not;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.content;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.redirectedUrl;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.view;
import static org.springframework.security.test.web.servlet.request.SecurityMockMvcRequestPostProcessors.csrf;

/**
 * Guards against the "application" model attribute colliding with a
 * Thymeleaf/Spring reserved context variable, which previously made every
 * {@code ${application.*}} expression in provider-review.html and
 * confirmation.html silently resolve to null (or throw), breaking the whole
 * provider approve/reject workflow and the post-apply confirmation page
 * without ever failing a unit test, since no test rendered these views.
 */
class ProviderApplicationReviewMvcTest extends MvcIntegrationTestBase {

    private Application seedSubmittedApplication(String providerEmail, String applicantEmail) {
        User provider = data.saveProvider(providerEmail);
        User applicant = data.saveApplicant(applicantEmail);
        Opportunity opportunity = data.saveOpportunity(provider);
        Application application = data.saveApplication(applicant, opportunity);
        application.setApplicationStatus("SUBMITTED");
        return applicationRepository.save(application);
    }

    @Test
    void reviewPageRendersRealApplicationDataInFormActions() throws Exception {
        String providerEmail = "review-prov-" + UUID.randomUUID() + "@org.co.zw";
        String applicantEmail = "review-app-" + UUID.randomUUID() + "@student.co.zw";
        Application application = seedSubmittedApplication(providerEmail, applicantEmail);
        Long id = application.getApplicationId();

        mockMvc.perform(get("/provider/applications/{id}", id)
                        .with(MvcTestSupport.asProvider(providerEmail)))
                .andExpect(status().isOk())
                .andExpect(view().name("applications/provider-review"))
                .andExpect(content().string(containsString("Test Scholarship")))
                .andExpect(content().string(containsString("SUBMITTED")))
                // The regression: every form action embedded the application id via
                // ${application.applicationId}; when that silently resolved to null
                // the URL became "/provider/applications//approve" (double slash).
                .andExpect(content().string(containsString(
                        "/provider/applications/" + id + "/approve")))
                .andExpect(content().string(containsString(
                        "/provider/applications/" + id + "/reject")))
                .andExpect(content().string(not(containsString(
                        "/provider/applications//approve"))));
    }

    @Test
    void approveTransitionsStatusAndUnlocksContactDetails() throws Exception {
        String providerEmail = "approve-prov-" + UUID.randomUUID() + "@org.co.zw";
        String applicantEmail = "approve-app-" + UUID.randomUUID() + "@student.co.zw";
        Application application = seedSubmittedApplication(providerEmail, applicantEmail);
        Long id = application.getApplicationId();

        mockMvc.perform(post("/provider/applications/{id}/approve", id)
                        .with(MvcTestSupport.asProvider(providerEmail))
                        .with(csrf())
                        .param("reason", "Strong academic profile."))
                .andExpect(status().is3xxRedirection())
                .andExpect(redirectedUrl("/provider/applications/" + id));

        Application reloaded = applicationRepository.findById(id).orElseThrow();
        org.junit.jupiter.api.Assertions.assertEquals("APPROVED", reloaded.getApplicationStatus());

        mockMvc.perform(get("/provider/applications/{id}", id)
                        .with(MvcTestSupport.asProvider(providerEmail)))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("APPROVED")))
                .andExpect(content().string(containsString("Contact details")))
                .andExpect(content().string(not(containsString("unlock after you approve"))));
    }

    @Test
    void rejectTransitionsStatusAndKeepsContactLocked() throws Exception {
        String providerEmail = "reject-prov-" + UUID.randomUUID() + "@org.co.zw";
        String applicantEmail = "reject-app-" + UUID.randomUUID() + "@student.co.zw";
        Application application = seedSubmittedApplication(providerEmail, applicantEmail);
        Long id = application.getApplicationId();

        mockMvc.perform(post("/provider/applications/{id}/reject", id)
                        .with(MvcTestSupport.asProvider(providerEmail))
                        .with(csrf())
                        .param("reason", "Does not meet eligibility criteria."))
                .andExpect(status().is3xxRedirection())
                .andExpect(redirectedUrl("/provider/applications"));

        Application reloaded = applicationRepository.findById(id).orElseThrow();
        org.junit.jupiter.api.Assertions.assertEquals("REJECTED", reloaded.getApplicationStatus());
        org.junit.jupiter.api.Assertions.assertEquals(
                "Does not meet eligibility criteria.", reloaded.getRejectionReason());
    }

    @Test
    void confirmationPageRendersRealApplicationData() throws Exception {
        String providerEmail = "conf-prov-" + UUID.randomUUID() + "@org.co.zw";
        String applicantEmail = "conf-app-" + UUID.randomUUID() + "@student.co.zw";
        Application application = seedSubmittedApplication(providerEmail, applicantEmail);
        Long id = application.getApplicationId();

        mockMvc.perform(get("/applications/{id}/confirmation", id)
                        .with(MvcTestSupport.asApplicant(applicantEmail)))
                .andExpect(status().isOk())
                .andExpect(view().name("applications/confirmation"))
                .andExpect(content().string(containsString("Test Scholarship")))
                .andExpect(content().string(containsString("Reference #")))
                .andExpect(content().string(containsString(">" + id + "<")));
    }
}
