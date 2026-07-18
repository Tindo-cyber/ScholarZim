package com.scholarzim;

import com.scholarzim.support.MvcIntegrationTestBase;
import org.junit.jupiter.api.Test;

import static org.hamcrest.Matchers.containsString;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.content;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

class ProductionPolishMvcTest extends MvcIntegrationTestBase {

    @Test
    void publicScholarshipsPageRendersContentShell() throws Exception {
        mockMvc.perform(get("/scholarships"))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("sz-page-shell")))
                .andExpect(content().string(containsString("Browse scholarships")))
                .andExpect(content().string(containsString("id=\"schKeyword\"")))
                .andExpect(content().string(containsString("production.css?v=60")));
    }

    @Test
    void registerPageHasAccessibleLabels() throws Exception {
        mockMvc.perform(get("/register"))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("for=\"regEmail\"")))
                .andExpect(content().string(containsString("autocomplete=\"email\"")))
                .andExpect(content().string(containsString("for=\"regConfirmPassword\"")));
    }

    @Test
    void landingFaqHasAccordionAria() throws Exception {
        mockMvc.perform(get("/"))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("aria-controls=\"faq2\"")))
                .andExpect(content().string(containsString("aria-expanded=\"false\"")));
    }
}
