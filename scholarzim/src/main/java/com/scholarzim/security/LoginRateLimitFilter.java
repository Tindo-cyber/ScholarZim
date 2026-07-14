package com.scholarzim.security;

import jakarta.servlet.*;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.core.annotation.Order;
import org.springframework.stereotype.Component;

import java.io.IOException;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.time.Duration;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;

import io.github.bucket4j.Bandwidth;
import io.github.bucket4j.Bucket;

@Component
@Order(1)
public class LoginRateLimitFilter implements Filter {

    private final Map<String, Bucket> authBuckets = new ConcurrentHashMap<>();
    private final Map<String, Bucket> providerRegisterBuckets = new ConcurrentHashMap<>();
    private final Map<String, Bucket> apiBuckets = new ConcurrentHashMap<>();
    private final int limitPerMinute;
    private final int providerRegisterPerHour;
    private final int forgotPasswordPerHour;
    private final int apiPerMinute;

    public LoginRateLimitFilter(
            @Value("${scholarzim.rate-limit.login-per-minute:10}") int limitPerMinute,
            @Value("${scholarzim.rate-limit.provider-register-per-hour:5}") int providerRegisterPerHour,
            @Value("${scholarzim.rate-limit.forgot-password-per-hour:5}") int forgotPasswordPerHour,
            @Value("${scholarzim.rate-limit.api-per-minute:60}") int apiPerMinute) {

        this.limitPerMinute = limitPerMinute;
        this.providerRegisterPerHour = providerRegisterPerHour;
        this.forgotPasswordPerHour = forgotPasswordPerHour;
        this.apiPerMinute = apiPerMinute;
    }

    @Override
    public void doFilter(ServletRequest request, ServletResponse response, FilterChain chain)
            throws IOException, ServletException {

        HttpServletRequest req = (HttpServletRequest) request;
        HttpServletResponse res = (HttpServletResponse) response;
        String path = req.getServletPath();
        String clientKey = req.getRemoteAddr();

        if ("POST".equalsIgnoreCase(req.getMethod())) {
            if ("/register/provider".equals(path)) {
                if (!consumeRedirect(providerRegisterBuckets, clientKey + ":provider-register",
                        providerRegisterBucket(), res,
                        "/register/provider?error=rate_limit")) {
                    return;
                }
            } else if ("/forgot-password".equals(path)) {
                if (!consumeRedirect(authBuckets, clientKey + ":forgot-password",
                        forgotPasswordBucket(), res,
                        "/forgot-password?error=rate_limit")) {
                    return;
                }
            } else if ("/login".equals(path)) {
                String role = req.getParameter("role");
                if (role == null || role.isBlank()) {
                    role = "student";
                }
                String redirect = "/login?role="
                        + URLEncoder.encode(role, StandardCharsets.UTF_8)
                        + "&error=rate_limit";
                if (!consumeRedirect(authBuckets, clientKey + ":/login", loginBucket(), res, redirect)) {
                    return;
                }
            } else if ("/register".equals(path)) {
                if (!consumeRedirect(authBuckets, clientKey + ":/register",
                        loginBucket(), res, "/register?error=rate_limit")) {
                    return;
                }
            }
        }

        if (path.startsWith("/api/") && !consumeApi(apiBuckets, clientKey + ":api", apiBucket(), res)) {
            return;
        }

        chain.doFilter(request, response);
    }

    private boolean consumeRedirect(
            Map<String, Bucket> store,
            String key,
            Bucket prototype,
            HttpServletResponse res,
            String redirectUrl) throws IOException {

        Bucket bucket = store.computeIfAbsent(key, k -> prototype);
        if (!bucket.tryConsume(1)) {
            res.sendRedirect(redirectUrl);
            return false;
        }
        return true;
    }

    private boolean consumeApi(
            Map<String, Bucket> store,
            String key,
            Bucket prototype,
            HttpServletResponse res) throws IOException {

        Bucket bucket = store.computeIfAbsent(key, k -> prototype);
        if (!bucket.tryConsume(1)) {
            res.sendError(429, "API rate limit exceeded. Please slow down.");
            return false;
        }
        return true;
    }

    private Bucket loginBucket() {
        return Bucket.builder()
                .addLimit(Bandwidth.builder()
                        .capacity(limitPerMinute)
                        .refillGreedy(limitPerMinute, Duration.ofMinutes(1))
                        .build())
                .build();
    }

    private Bucket providerRegisterBucket() {
        return Bucket.builder()
                .addLimit(Bandwidth.builder()
                        .capacity(providerRegisterPerHour)
                        .refillGreedy(providerRegisterPerHour, Duration.ofHours(1))
                        .build())
                .build();
    }

    private Bucket forgotPasswordBucket() {
        return Bucket.builder()
                .addLimit(Bandwidth.builder()
                        .capacity(forgotPasswordPerHour)
                        .refillGreedy(forgotPasswordPerHour, Duration.ofHours(1))
                        .build())
                .build();
    }

    private Bucket apiBucket() {
        return Bucket.builder()
                .addLimit(Bandwidth.builder()
                        .capacity(apiPerMinute)
                        .refillGreedy(apiPerMinute, Duration.ofMinutes(1))
                        .build())
                .build();
    }
}
