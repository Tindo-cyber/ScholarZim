package com.scholarzim;

import com.scholarzim.entity.ApplicantProfile;
import com.scholarzim.entity.User;
import com.scholarzim.support.MvcIntegrationTestBase;
import com.scholarzim.support.MvcTestSupport;
import org.junit.jupiter.api.Test;
import org.springframework.mock.web.MockMultipartFile;
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
    void profilePageShowsResultsRequiredWarning() throws Exception {
        String email = "warn-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicant(email);

        mockMvc.perform(get("/applicant/profile")
                        .param("resultsRequired", "1")
                        .with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(view().name("applicant/profile"))
                .andExpect(content().string(containsString("results certificate")))
                .andExpect(content().string(containsString("sz-profile-completion")))
                .andExpect(content().string(containsString("Profile completion")));
    }

    @Test
    @WithMockUser(roles = "APPLICANT")
    void profilePageShowsCompletionModuleAndMissingDocuments() throws Exception {
        String email = "completion-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicant(email);

        mockMvc.perform(get("/applicant/profile").with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("sz-profile-completion")))
                .andExpect(content().string(containsString("CV")))
                .andExpect(content().string(containsString("Transcript")))
                .andExpect(content().string(containsString("Passport")))
                .andExpect(content().string(containsString("Recommendation Letter")))
                .andExpect(content().string(containsString("Getting Started")))
                .andExpect(content().string(containsString("Profile Champion")));
    }

    @Test
    @WithMockUser(roles = "APPLICANT")
    void documentUploadUpdatesProfileCompletion() throws Exception {
        String email = "upload-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicant(email);

        mockMvc.perform(multipart("/applicant/profile/documents/cv")
                        .file(new MockMultipartFile(
                                "file", "cv.pdf", "application/pdf", "%PDF-1.4 cv".getBytes()))
                        .with(csrf())
                        .with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().is3xxRedirection())
                .andExpect(redirectedUrl("/applicant/profile"))
                .andExpect(flash().attribute("successMessage", "CV uploaded successfully."));

        ApplicantProfile profile = applicantProfileRepository.findByUser(
                userRepository.findByEmail(email).orElseThrow()).orElseThrow();
        assertNotNull(profile.getCvPath());
        assertNotNull(profile.getCvFilename());
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
