package com.scholarzim;

import com.scholarzim.support.MvcIntegrationTestBase;
import org.junit.jupiter.api.Test;
import org.springframework.http.MediaType;

import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.jsonPath;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

class PublicApiMvcTest extends MvcIntegrationTestBase {

    @Test
    void publicStatsReturnsJson() throws Exception {
        mockMvc.perform(get("/api/public/stats").accept(MediaType.APPLICATION_JSON))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.totalScholarships").isNumber())
                .andExpect(jsonPath("$.totalApplications").isNumber());
    }

    @Test
    void publicScholarshipsListReturnsJsonArray() throws Exception {
        mockMvc.perform(get("/api/public/scholarships").accept(MediaType.APPLICATION_JSON))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$").isArray());
    }

    @Test
    void publicScholarshipDetailReturns404WhenMissing() throws Exception {
        mockMvc.perform(get("/api/public/scholarships/999999").accept(MediaType.APPLICATION_JSON))
                .andExpect(status().isNotFound());
    }
}
