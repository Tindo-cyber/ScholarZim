package com.scholarzim;

import com.scholarzim.support.MvcIntegrationTestBase;
import org.junit.jupiter.api.Test;
import org.springframework.test.context.TestPropertySource;

import static org.hamcrest.Matchers.containsString;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.header;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

@TestPropertySource(properties = "scholarzim.assets.long-cache=true")
class StaticResourceCacheMvcTest extends MvcIntegrationTestBase {

    @Test
    void staticCssUsesLongCacheWhenEnabled() throws Exception {
        mockMvc.perform(get("/css/tokens.css"))
                .andExpect(status().isOk())
                .andExpect(header().string("Cache-Control", containsString("max-age")));
    }

    @Test
    void staticImagesUseLongCacheWhenEnabled() throws Exception {
        mockMvc.perform(get("/images/auth-education.webp"))
                .andExpect(status().isOk())
                .andExpect(header().string("Cache-Control", containsString("max-age")));
    }
}
