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

class SkeletonPagesMvcTest extends MvcIntegrationTestBase {

    @Test
    @WithMockUser(roles = "APPLICANT")
    void dashboardRendersContentWithoutSkeleton() throws Exception {
        String email = "skel-dash-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicant(email);

        mockMvc.perform(get("/applicant/dashboard").with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("sz-page-shell")))
                .andExpect(content().string(containsString("sz-page-shell__content")))
                .andExpect(content().string(not(containsString("sz-page-shell__skeleton"))))
                .andExpect(content().string(not(containsString("spinner-border"))));
    }

    @Test
    @WithMockUser(roles = "APPLICANT")
    void profileRendersContentWithoutSkeleton() throws Exception {
        String email = "skel-profile-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicant(email);

        mockMvc.perform(get("/applicant/profile").with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("data-page=\"profile\"")))
                .andExpect(content().string(not(containsString("sz-page-shell__skeleton"))))
                .andExpect(content().string(not(containsString("spinner-border"))));
    }

    @Test
    @WithMockUser(roles = "APPLICANT")
    void notificationsRendersContentWithoutSkeleton() throws Exception {
        String email = "skel-notif-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicant(email);

        mockMvc.perform(get("/notifications").with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("data-page=\"notifications\"")))
                .andExpect(content().string(containsString("Notification Center")))
                .andExpect(content().string(not(containsString("sz-page-shell__skeleton"))))
                .andExpect(content().string(not(containsString("spinner-border"))));
    }

    @Test
    @WithMockUser(roles = "APPLICANT")
    void applicationsRendersContentWithoutSkeleton() throws Exception {
        String email = "skel-apps-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicant(email);

        mockMvc.perform(get("/my-applications").with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("data-page=\"applications\"")))
                .andExpect(content().string(not(containsString("sz-page-shell__skeleton"))))
                .andExpect(content().string(not(containsString("spinner-border"))));
    }

    @Test
    @WithMockUser(roles = "APPLICANT")
    void messagesRendersContentWithoutSkeleton() throws Exception {
        String email = "skel-msg-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicant(email);

        mockMvc.perform(get("/messages").with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("data-page=\"messages\"")))
                .andExpect(content().string(not(containsString("sz-page-shell__skeleton"))))
                .andExpect(content().string(not(containsString("spinner-border"))));
    }

    @Test
    void publicScholarshipsRendersContentWithoutSkeleton() throws Exception {
        mockMvc.perform(get("/scholarships"))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("sz-page-shell")))
                .andExpect(content().string(not(containsString("sz-page-shell__skeleton"))))
                .andExpect(content().string(not(containsString("spinner-border"))));
    }

    @Test
    @WithMockUser(roles = "APPLICANT")
    void opportunitiesListRendersContentWithoutSkeleton() throws Exception {
        String email = "skel-opp-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicant(email);

        mockMvc.perform(get("/opportunities").with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(content().string(not(containsString("sz-page-shell__skeleton"))))
                .andExpect(content().string(not(containsString("spinner-border"))));
    }
}
