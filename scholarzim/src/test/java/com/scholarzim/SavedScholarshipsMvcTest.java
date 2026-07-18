package com.scholarzim;

import com.scholarzim.entity.ApplicantProfile;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.User;
import com.scholarzim.service.SavedScholarshipService;
import com.scholarzim.support.MvcIntegrationTestBase;
import com.scholarzim.support.MvcTestSupport;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;

import java.util.UUID;

import static org.hamcrest.Matchers.containsString;
import static org.hamcrest.Matchers.not;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.content;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.view;

class SavedScholarshipsMvcTest extends MvcIntegrationTestBase {

    @Autowired
    private SavedScholarshipService savedScholarshipService;

    @Test
    void savedPageShowsBookmarksWithoutErrorWidget() throws Exception {
        String email = "saved-" + UUID.randomUUID() + "@student.co.zw";
        ApplicantProfile profile = data.saveApplicantWithResultsCertificate(email);
        User applicant = profile.getUser();
        User provider = data.saveProvider("prov-saved-" + UUID.randomUUID() + "@org.co.zw");
        Opportunity opportunity = data.saveOpportunity(provider);
        savedScholarshipService.save(applicant.getEmail(), opportunity.getOpportunityId());

        mockMvc.perform(get("/applicant/saved").with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(view().name("applicant/saved"))
                .andExpect(content().string(containsString("Saved scholarships")))
                .andExpect(content().string(containsString(opportunity.getTitle())))
                .andExpect(content().string(not(containsString("Unable to load saved scholarships"))))
                .andExpect(content().string(not(containsString("Nothing saved yet"))));
    }

    @Test
    void savedPageHidesEmptyWidgetWhenNone() throws Exception {
        String email = "saved-empty-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicant(email);

        mockMvc.perform(get("/applicant/saved").with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("Saved scholarships")))
                .andExpect(content().string(not(containsString("Nothing saved yet"))))
                .andExpect(content().string(not(containsString("Unable to load saved scholarships"))));
    }
}
