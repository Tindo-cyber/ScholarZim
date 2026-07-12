package com.scholarzim;

import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.User;
import com.scholarzim.support.MvcIntegrationTestBase;
import com.scholarzim.support.MvcTestSupport;
import org.junit.jupiter.api.Test;
import org.springframework.http.MediaType;
import org.springframework.security.test.context.support.WithMockUser;

import java.util.UUID;

import static org.springframework.security.test.web.servlet.request.SecurityMockMvcRequestPostProcessors.csrf;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.delete;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.jsonPath;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

class ApplicantApiMvcTest extends MvcIntegrationTestBase {

    @Test
    @WithMockUser(roles = "APPLICANT")
    void recommendationsRequireApplicantProfile() throws Exception {
        String email = "api-rec-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicantWithResultsCertificate(email);

        mockMvc.perform(get("/api/applicant/recommendations")
                        .accept(MediaType.APPLICATION_JSON)
                        .with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.content").isArray());
    }

    @Test
    @WithMockUser(roles = "APPLICANT")
    void savedScholarshipCrud() throws Exception {
        String email = "api-saved-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicant(email);
        User provider = data.saveProvider("api-prov-" + UUID.randomUUID() + "@org.co.zw");
        Opportunity opportunity = data.saveOpportunity(provider);

        mockMvc.perform(get("/api/applicant/saved")
                        .accept(MediaType.APPLICATION_JSON)
                        .with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.content").isArray());

        mockMvc.perform(post("/api/applicant/saved/{id}", opportunity.getOpportunityId())
                        .with(csrf())
                        .with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.status").value("saved"));

        mockMvc.perform(get("/api/applicant/saved")
                        .accept(MediaType.APPLICATION_JSON)
                        .with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.content.length()").value(1));

        mockMvc.perform(delete("/api/applicant/saved/{id}", opportunity.getOpportunityId())
                        .with(csrf())
                        .with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.status").value("removed"));
    }

    @Test
    void applicantApiRequiresAuthentication() throws Exception {
        mockMvc.perform(get("/api/applicant/saved").accept(MediaType.APPLICATION_JSON))
                .andExpect(status().is3xxRedirection());
    }
}
