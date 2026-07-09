package com.scholarzim.security;

import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.AuditService;
import com.scholarzim.util.AuditAction;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import org.springframework.lang.NonNull;
import org.springframework.security.core.Authentication;
import org.springframework.security.web.authentication.AuthenticationSuccessHandler;
import org.springframework.stereotype.Component;

import java.io.IOException;


@Component
public class RoleBasedAuthenticationSuccessHandler
        implements AuthenticationSuccessHandler {

    private final AuditService auditService;
    private final UserRepository userRepository;

    public RoleBasedAuthenticationSuccessHandler(
            AuditService auditService,
            UserRepository userRepository) {

        this.auditService = auditService;
        this.userRepository = userRepository;
    }

    @Override
    public void onAuthenticationSuccess(
            HttpServletRequest request,
            HttpServletResponse response,
            @NonNull Authentication authentication)
            throws IOException {

        String email = authentication.getName();
        userRepository.findByEmail(email).ifPresent(user -> auditService.log(
                email,
                AuditAction.LOGIN_SUCCESS,
                "USER",
                user.getUserId(),
                "Successful login"));

        response.sendRedirect(request.getContextPath()
                + RoleRedirectUtil.getDashboardUrl(authentication.getAuthorities()));
    }
}
