package com.scholarzim;

import com.scholarzim.entity.ApplicantProfile;
import com.scholarzim.entity.User;
import com.scholarzim.support.MvcIntegrationTestBase;
import com.scholarzim.support.MvcTestSupport;
import org.junit.jupiter.api.Test;
import org.springframework.security.test.context.support.WithMockUser;

import java.util.UUID;

import static com.scholarzim.support.TestDataFactory.resultsPdf;
import static org.hamcrest.Matchers.containsString;
import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNotNull;
import static org.springframework.security.test.web.servlet.request.SecurityMockMvcRequestPostProcessors.csrf;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.multipart;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.content;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.flash;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.redirectedUrl;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.view;

class ApplicantProfileMvcTest extends MvcIntegrationTestBase {

    @Test
    @WithMockUser(roles = "APPLICANT")
    void profilePageShowsResultsRequiredWarning() throws Exception {
        String email = "warn-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicant(email);

        mockMvc.perform(get("/applicant/profile")
                        .param("resultsRequired", "1")
                        .with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(view().name("applicant/profile"))
                .andExpect(content().string(containsString("results certificate")));
    }

    @Test
    @WithMockUser(roles = "APPLICANT")
    void profileUpdateTextOnlyWhenCertExistsSucceeds() throws Exception {
        String email = "update-" + UUID.randomUUID() + "@student.co.zw";
        User applicant = data.saveApplicantWithResultsCertificate(email).getUser();

        mockMvc.perform(multipart("/applicant/profile")
                        .param("educationLevel", "Undergraduate")
                        .param("institutionName", "NUST")
                        .param("fieldOfStudy", "Law")
                        .param("country", "Zimbabwe")
                        .param("province", "Bulawayo")
                        .param("academicResults", "Updated summary")
                        .with(csrf())
                        .with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().is3xxRedirection())
                .andExpect(redirectedUrl("/applicant/dashboard"))
                .andExpect(flash().attribute("successMessage",
                        "Academic profile and results certificate saved."));

        ApplicantProfile profile = applicantProfileRepository.findByUser(applicant).orElseThrow();
        assertEquals("NUST", profile.getInstitutionName());
        assertNotNull(profile.getResultsCertificatePath());
    }
}
