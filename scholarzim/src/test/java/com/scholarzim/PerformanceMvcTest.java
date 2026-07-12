package com.scholarzim;

import com.scholarzim.support.MvcIntegrationTestBase;
import com.scholarzim.support.MvcTestSupport;
import org.junit.jupiter.api.Test;

import java.util.UUID;

import static org.hamcrest.Matchers.containsString;
import static org.hamcrest.Matchers.not;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.content;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

class PerformanceMvcTest extends MvcIntegrationTestBase {

    @Test
    void landingUsesLightweightPublicScripts() throws Exception {
        mockMvc.perform(get("/"))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("public-shell.js?v=47")))
                .andExpect(content().string(containsString("performance.js?v=47")))
                .andExpect(content().string(containsString("performance.css?v=47")))
                .andExpect(content().string(containsString("Manrope")))
                .andExpect(content().string(containsString("rel=\"preload\"")))
                .andExpect(content().string(containsString("auth-education.webp")))
                .andExpect(content().string(not(containsString("app.js?v=47"))))
                .andExpect(content().string(containsString("defer")));
    }

    @Test
    void dashboardKeepsAppShellScripts() throws Exception {
        String email = "perf-" + UUID.randomUUID() + "@student.co.zw";
        data.saveApplicant(email);

        mockMvc.perform(get("/applicant/profile").with(MvcTestSupport.asApplicant(email)))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("app.js?v=47")))
                .andExpect(content().string(containsString("performance.css?v=47")))
                .andExpect(content().string(containsString("defer")));
    }
}
