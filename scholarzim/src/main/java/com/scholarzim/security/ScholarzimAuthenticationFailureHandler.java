package com.scholarzim.security;

import com.scholarzim.entity.User;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.AuditService;
import com.scholarzim.util.AuditAction;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import lombok.extern.slf4j.Slf4j;
import org.springframework.security.authentication.DisabledException;
import org.springframework.security.core.AuthenticationException;
import org.springframework.security.web.authentication.SimpleUrlAuthenticationFailureHandler;
import org.springframework.stereotype.Component;

import java.io.IOException;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;


@Slf4j
@Component
public class ScholarzimAuthenticationFailureHandler extends SimpleUrlAuthenticationFailureHandler {

    private final UserRepository userRepository;
    private final AuditService auditService;

    public ScholarzimAuthenticationFailureHandler(
            UserRepository userRepository,
            AuditService auditService) {
        this.userRepository = userRepository;
        this.auditService = auditService;
        setDefaultFailureUrl("/login?error=credentials");
    }

    @Override
    public void onAuthenticationFailure(
            HttpServletRequest request,
            HttpServletResponse response,
            AuthenticationException exception) throws IOException {

        String email = request.getParameter("username");
        String role = "student";
        String error = "credentials";

        if (exception instanceof DisabledException disabled) {
            String msg = disabled.getMessage() != null ? disabled.getMessage().toLowerCase() : "";
            if (msg.contains("verify your email")) {
                error = "unverified";
            } else {
                error = "disabled";
            }
        }

        if (email != null && !email.isBlank()) {
            try {
                var userOpt = userRepository.findByEmailWithRole(email.trim());
                if (userOpt.isPresent()) {
                    User user = userOpt.get();
                    if (user.getRole() != null && "ROLE_PROVIDER".equals(user.getRole().getRoleName())) {
                        role = "provider";
                    }
                    if (!"unverified".equals(error)) {
                        String status = user.getAccountStatus();
                        if (!isActive(status)) {
                            if ("PENDING_APPROVAL".equals(status)) {
                                error = "pending";
                            } else if ("REJECTED".equals(status)) {
                                error = "rejected";
                            } else if ("SUSPENDED".equals(status)) {
                                error = "suspended";
                            } else {
                                error = "disabled";
                            }
                        }
                    }
                }
            } catch (Exception ex) {
                log.warn("Could not enrich login failure for {}: {}", email, ex.getMessage());
            }

            try {
                auditService.log(
                        email.trim(),
                        AuditAction.LOGIN_FAILURE,
                        "USER",
                        null,
                        "Failed login attempt");
            } catch (Exception ex) {
                log.warn("Login failure audit write failed for {}: {}", email, ex.getMessage());
            }
        }

        String redirect = "/login?role=" + role + "&error=" + URLEncoder.encode(error, StandardCharsets.UTF_8);
        getRedirectStrategy().sendRedirect(request, response, redirect);
    }

    private static boolean isActive(String status) {
        return status == null || "ACTIVE".equalsIgnoreCase(status);
    }
}
