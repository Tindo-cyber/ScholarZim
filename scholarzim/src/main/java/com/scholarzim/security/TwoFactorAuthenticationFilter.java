package com.scholarzim.security;

import com.scholarzim.service.TotpService;
import jakarta.servlet.FilterChain;
import jakarta.servlet.ServletException;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import jakarta.servlet.http.HttpSession;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.lang.NonNull;
import org.springframework.security.authentication.AnonymousAuthenticationToken;
import org.springframework.security.core.Authentication;
import org.springframework.security.core.context.SecurityContextHolder;
import org.springframework.stereotype.Component;
import org.springframework.web.filter.OncePerRequestFilter;

import java.io.IOException;
import java.util.Set;

@Component
public class TwoFactorAuthenticationFilter extends OncePerRequestFilter {

    public static final String TWO_FA_VERIFIED = "TWO_FA_VERIFIED";

    private static final Set<String> ALLOWED_PATHS = Set.of(
            "/login/2fa-challenge",
            "/logout",
            "/css/",
            "/js/",
            "/images/",
            "/icons/",
            "/error",
            "/403");

    private final TotpService totpService;
    private final boolean twoFaEnabled;

    public TwoFactorAuthenticationFilter(
            TotpService totpService,
            @Value("${scholarzim.security.2fa.enabled:false}") boolean twoFaEnabled) {

        this.totpService = totpService;
        this.twoFaEnabled = twoFaEnabled;
    }

    @Override
    protected void doFilterInternal(
            @NonNull HttpServletRequest request,
            @NonNull HttpServletResponse response,
            @NonNull FilterChain filterChain) throws ServletException, IOException {

        if (!twoFaEnabled) {
            filterChain.doFilter(request, response);
            return;
        }

        Authentication auth = SecurityContextHolder.getContext().getAuthentication();
        if (auth == null || !auth.isAuthenticated() || auth instanceof AnonymousAuthenticationToken) {
            filterChain.doFilter(request, response);
            return;
        }

        String path = request.getRequestURI();
        if (isAllowedWithoutTwoFactor(path)) {
            filterChain.doFilter(request, response);
            return;
        }

        HttpSession session = request.getSession(false);
        if (session != null && Boolean.TRUE.equals(session.getAttribute(TWO_FA_VERIFIED))) {
            filterChain.doFilter(request, response);
            return;
        }

        String email = auth.getName();
        if (!totpService.requiresTwoFactor(email)) {
            filterChain.doFilter(request, response);
            return;
        }

        response.sendRedirect(request.getContextPath() + "/login/2fa-challenge");
    }

    private static boolean isAllowedWithoutTwoFactor(String path) {
        if (ALLOWED_PATHS.contains(path)) {
            return true;
        }
        return ALLOWED_PATHS.stream().anyMatch(prefix -> prefix.endsWith("/") && path.startsWith(prefix));
    }
}
