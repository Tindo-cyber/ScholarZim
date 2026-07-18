package com.scholarzim;

import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.autoconfigure.web.servlet.AutoConfigureMockMvc;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.test.context.ActiveProfiles;
import org.springframework.test.web.servlet.MockMvc;

import static org.hamcrest.Matchers.containsString;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.content;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.view;

@SpringBootTest
@AutoConfigureMockMvc
@ActiveProfiles("test")
class AuthMvcTest {

    @Autowired
    private MockMvc mockMvc;

    @Test
    void loginPageLoads() throws Exception {
        mockMvc.perform(get("/login"))
                .andExpect(status().isOk())
                .andExpect(view().name("auth/login"))
                .andExpect(content().string(containsString("Skip to main content")))
                .andExpect(content().string(containsString("name=\"username\"")))
                .andExpect(content().string(containsString("name=\"password\"")))
                .andExpect(content().string(containsString("Sign in")))
                .andExpect(content().string(containsString("_csrf")))
                .andExpect(content().string(org.hamcrest.Matchers.not(containsString("Something went wrong"))))
                .andExpect(content().string(org.hamcrest.Matchers.not(containsString("Error 200"))));
    }

    @Test
    void registerPageLoads() throws Exception {
        mockMvc.perform(get("/register"))
                .andExpect(status().isOk())
                .andExpect(view().name("auth/register"))
                .andExpect(content().string(containsString("Create account")))
                .andExpect(content().string(containsString("name=\"fullName\"")))
                .andExpect(content().string(org.hamcrest.Matchers.not(containsString("Something went wrong"))));
    }

    @Test
    void homePageLoads() throws Exception {
        mockMvc.perform(get("/"))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("Skip to main content")))
                .andExpect(content().string(containsString("id=\"main-content\"")))
                .andExpect(content().string(containsString("sz-home-v4")))
                .andExpect(content().string(containsString("id=\"categories\"")))
                .andExpect(content().string(containsString("id=\"about\"")))
                .andExpect(content().string(containsString("id=\"how-it-works\"")))
                .andExpect(content().string(containsString("id=\"features\"")))
                .andExpect(content().string(containsString("id=\"providers\"")))
                .andExpect(content().string(containsString("id=\"contact\"")))
                .andExpect(content().string(containsString("id=\"success-stories\"")))
                .andExpect(content().string(containsString("id=\"newsletter\"")))
                .andExpect(content().string(containsString("sz-count-up")))
                .andExpect(content().string(containsString("landing.css")));
    }

    @Test
    void loginPageIsUniversalForAllRoles() throws Exception {
        mockMvc.perform(get("/login"))
                .andExpect(status().isOk())
                .andExpect(view().name("auth/login"))
                .andExpect(content().string(containsString("Sign in to ScholarZim")))
                .andExpect(content().string(containsString("Register as student")))
                .andExpect(content().string(containsString("Apply as provider")))
                .andExpect(content().string(org.hamcrest.Matchers.not(containsString("Student sign in"))))
                .andExpect(content().string(org.hamcrest.Matchers.not(containsString("Provider sign in"))))
                .andExpect(content().string(org.hamcrest.Matchers.not(containsString("sz-auth-public-tabs"))));
    }

    @Test
    void loginIgnoresLegacyRoleQueryParam() throws Exception {
        mockMvc.perform(get("/login").param("role", "provider"))
                .andExpect(status().isOk())
                .andExpect(view().name("auth/login"))
                .andExpect(content().string(containsString("Sign in to ScholarZim")));
    }

    @Test
    void scholarshipsBrowsePageLoads() throws Exception {
        mockMvc.perform(get("/scholarships"))
                .andExpect(status().isOk())
                .andExpect(view().name("public/scholarships"));
    }

    @Test
    void scholarshipsKeywordSearchBinds() throws Exception {
        mockMvc.perform(get("/scholarships").param("keyword", "engineering"))
                .andExpect(status().isOk())
                .andExpect(view().name("public/scholarships"));
    }

    @Test
    void providerRegisterPageLoads() throws Exception {
        mockMvc.perform(get("/register/provider"))
                .andExpect(status().isOk())
                .andExpect(view().name("auth/register-provider"))
                .andExpect(content().string(containsString("Submit for approval")))
                .andExpect(content().string(containsString("name=\"fullName\"")))
                .andExpect(content().string(org.hamcrest.Matchers.not(containsString("Something went wrong"))));
    }

    @Test
    void loginPageShowsPendingInfo() throws Exception {
        mockMvc.perform(get("/login").param("pending", "1"))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("pending admin review")));
    }
}
