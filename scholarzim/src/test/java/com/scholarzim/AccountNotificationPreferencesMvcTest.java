package com.scholarzim;

import com.scholarzim.entity.User;
import com.scholarzim.support.MvcIntegrationTestBase;
import com.scholarzim.support.MvcTestSupport;
import org.junit.jupiter.api.Test;
import org.springframework.security.test.context.support.WithMockUser;

import java.util.UUID;

import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertTrue;
import static org.hamcrest.Matchers.containsString;
import static org.springframework.security.test.web.servlet.request.SecurityMockMvcRequestPostProcessors.csrf;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.content;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.flash;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.redirectedUrl;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

class AccountNotificationPreferencesMvcTest extends MvcIntegrationTestBase {

    @Test
    @WithMockUser(roles = "APPLICANT")
    void settingsPageShowsNotificationTogglesCheckedByDefault() throws Exception {
        String email = "notifprefs-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicant(email);

        mockMvc.perform(get("/account/security").with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("Email notifications")))
                .andExpect(content().string(containsString("emailNotifyApplications")));
    }

    @Test
    @WithMockUser(roles = "APPLICANT")
    void savingPreferencesPersistsUncheckedBoxesAsFalse() throws Exception {
        String email = "notifprefs-save-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicant(email);

        // Only "scholarships" is submitted -- an unchecked checkbox sends no param at all,
        // matching how a browser form actually behaves.
        mockMvc.perform(post("/account/notification-preferences")
                        .param("emailNotifyScholarships", "on")
                        .with(csrf())
                        .with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().is3xxRedirection())
                .andExpect(redirectedUrl("/account/security"))
                .andExpect(flash().attribute("successMessage", "Notification preferences saved."));

        User saved = userRepository.findByEmail(email).orElseThrow();
        assertFalse(saved.isEmailNotifyApplications());
        assertTrue(saved.isEmailNotifyScholarships());
        assertFalse(saved.isEmailNotifySystem());
    }
}
