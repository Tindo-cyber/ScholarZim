package com.scholarzim.security;

import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.AuditService;
import com.scholarzim.service.TotpService;
import com.scholarzim.util.AuditAction;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import jakarta.servlet.http.HttpSession;
import org.springframework.lang.NonNull;
import org.springframework.security.core.Authentication;
import org.springframework.security.web.authentication.AuthenticationSuccessHandler;
import org.springframework.stereotype.Component;

import java.io.IOException;


@Component
public class RoleBasedAuthenticationSuccessHandler
        implements AuthenticationSuccessHandler {

    private final TotpService totpService;
    private final AuditService auditService;
    private final UserRepository userRepository;

    public RoleBasedAuthenticationSuccessHandler(
            TotpService totpService,
            AuditService auditService,
            UserRepository userRepository) {

        this.totpService = totpService;
        this.auditService = auditService;
        this.userRepository = userRepository;
    }

    @Override
    public void onAuthenticationSuccess(
            HttpServletRequest request,
            HttpServletResponse response,
            @NonNull Authentication authentication)
            throws IOException {

        HttpSession session = request.getSession();
        session.removeAttribute(TwoFactorAuthenticationFilter.TWO_FA_VERIFIED);

        String email = authentication.getName();
        userRepository.findByEmail(email).ifPresent(user -> auditService.log(
                email,
                AuditAction.LOGIN_SUCCESS,
                "USER",
                user.getUserId(),
                "Successful login"));

        if (totpService.requiresTwoFactor(email)) {
            response.sendRedirect(request.getContextPath() + "/login/2fa-challenge");
            return;
        }

        session.setAttribute(TwoFactorAuthenticationFilter.TWO_FA_VERIFIED, Boolean.TRUE);
        response.sendRedirect(request.getContextPath()
                + RoleRedirectUtil.getDashboardUrl(authentication.getAuthorities()));
    }
}
