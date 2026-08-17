package com.scholarzim;

import com.scholarzim.service.NotificationService;
import com.scholarzim.support.MvcIntegrationTestBase;
import com.scholarzim.support.MvcTestSupport;
import com.scholarzim.util.NotificationType;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.security.test.context.support.WithMockUser;

import java.util.UUID;

import static org.hamcrest.Matchers.containsString;
import static org.hamcrest.Matchers.not;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.content;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.view;


class NotificationCenterMvcTest extends MvcIntegrationTestBase {

    @Autowired
    private NotificationService notificationService;

    @Test
    @WithMockUser(roles = "APPLICANT")
    void notificationCenterRendersCategoriesSearchAndUnreadState() throws Exception {
        String email = "notifications-" + UUID.randomUUID() + "@student.co.zw";
        var applicant = data.saveApplicant(email);
        notificationService.notifyUser(
                applicant,
                NotificationType.DEADLINE_REMINDER,
                "Midlands State University deadline is approaching",
                "/opportunities/42",
                42L);

        mockMvc.perform(get("/notifications").with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(view().name("notifications/list"))
                .andExpect(content().string(containsString("Notification Center")))
                .andExpect(content().string(containsString("Applications")))
                .andExpect(content().string(containsString("Scholarships")))
                .andExpect(content().string(containsString("System")))
                .andExpect(content().string(containsString("Search notifications")))
                .andExpect(content().string(containsString("Mark all read")))
                .andExpect(content().string(containsString("Midlands State University deadline is approaching")))
                .andExpect(content().string(containsString("sz-notification-card--unread")))
                .andExpect(content().string(not(containsString("Something went wrong"))));
    }

    @Test
    @WithMockUser(roles = "APPLICANT")
    void notificationCenterFiltersByCategoryAndSearch() throws Exception {
        String email = "notification-filter-" + UUID.randomUUID() + "@student.co.zw";
        var applicant = data.saveApplicant(email);
        notificationService.notifyUser(
                applicant,
                NotificationType.NEW_OPPORTUNITY,
                "A new engineering scholarship matches your profile",
                "/opportunities",
                9L);
        notificationService.notifyUser(
                applicant,
                NotificationType.PROFILE_INCOMPLETE,
                "Complete your profile to see better matches",
                "/applicant/profile",
                10L);

        mockMvc.perform(get("/notifications")
                        .param("category", "SCHOLARSHIPS")
                        .param("q", "engineering")
                        .with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("A new engineering scholarship matches your profile")))
                .andExpect(content().string(containsString("category=SCHOLARSHIPS")));
    }
}
