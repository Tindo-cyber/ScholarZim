package com.scholarzim;

import com.scholarzim.support.MvcIntegrationTestBase;
import org.junit.jupiter.api.Test;

import java.util.UUID;

import static org.hamcrest.Matchers.containsString;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.content;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

class ScholarshipCardMvcTest extends MvcIntegrationTestBase {

    @Test
    void publicScholarshipsPageRendersPremiumCards() throws Exception {
        var provider = data.saveProvider("card-prov-" + UUID.randomUUID() + "@org.co.zw");
        data.saveOpportunity(provider);

        mockMvc.perform(get("/scholarships"))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("sz-scholarship-card")))
                .andExpect(content().string(containsString("sz-scholarship-card__logo")))
                .andExpect(content().string(containsString("sz-scholarship-card__countdown")))
                .andExpect(content().string(containsString("Quick Apply")));
    }
}
