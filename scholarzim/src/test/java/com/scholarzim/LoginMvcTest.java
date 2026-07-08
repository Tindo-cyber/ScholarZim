package com.scholarzim;

import com.scholarzim.entity.Role;
import com.scholarzim.entity.User;
import com.scholarzim.support.MvcIntegrationTestBase;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.security.crypto.password.PasswordEncoder;

import java.util.UUID;

import static org.hamcrest.Matchers.containsString;
import static org.springframework.security.test.web.servlet.request.SecurityMockMvcRequestPostProcessors.csrf;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.content;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.redirectedUrl;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

class LoginMvcTest extends MvcIntegrationTestBase {

    @Autowired
    private PasswordEncoder passwordEncoder;

    @Test
    void loginPageIncludesCsrfField() throws Exception {
        mockMvc.perform(get("/login"))
                .andExpect(status().isOk())
                .andExpect(content().string(containsString("name=\"_csrf\"")));
    }

    @Test
    void applicantCanSignInWithValidCredentials() throws Exception {
        String email = "login-" + UUID.randomUUID() + "@student.co.zw";
        Role role = roleRepository.findByRoleName("ROLE_APPLICANT").orElseGet(() -> {
            Role created = new Role();
            created.setRoleName("ROLE_APPLICANT");
            created.setDescription("Applicant");
            return roleRepository.save(created);
        });

        User user = new User();
        user.setEmail(email);
        user.setFullName("Login Test");
        user.setPasswordHash(passwordEncoder.encode("Password123!"));
        user.setRole(role);
        user.setAccountStatus("ACTIVE");
        userRepository.save(user);

        mockMvc.perform(post("/login")
                        .param("username", email)
                        .param("password", "Password123!")
                        .with(csrf()))
                .andExpect(status().is3xxRedirection())
                .andExpect(redirectedUrl("/applicant/dashboard"));
    }
}
