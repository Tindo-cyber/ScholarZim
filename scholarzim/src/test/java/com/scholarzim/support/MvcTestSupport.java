package com.scholarzim.support;

import org.springframework.security.core.context.SecurityContextHolder;
import org.springframework.security.test.context.TestSecurityContextHolder;
import org.springframework.security.test.web.servlet.request.SecurityMockMvcRequestPostProcessors;
import org.springframework.test.web.servlet.request.RequestPostProcessor;

public final class MvcTestSupport {

    private MvcTestSupport() {
    }

    public static void clearSecurityContext() {
        SecurityContextHolder.clearContext();
        TestSecurityContextHolder.clearContext();
    }

    public static RequestPostProcessor asApplicant(String email) {
        return SecurityMockMvcRequestPostProcessors.user(email).roles("APPLICANT");
    }

    public static RequestPostProcessor asProvider(String email) {
        return SecurityMockMvcRequestPostProcessors.user(email).roles("PROVIDER");
    }

    public static RequestPostProcessor asAdmin(String email) {
        return SecurityMockMvcRequestPostProcessors.user(email).roles("ADMIN");
    }
}
