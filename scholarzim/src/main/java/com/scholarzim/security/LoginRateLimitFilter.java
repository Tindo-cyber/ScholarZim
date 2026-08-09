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

import com.github.benmanes.caffeine.cache.Cache;
import com.github.benmanes.caffeine.cache.Caffeine;
import io.github.bucket4j.Bandwidth;
import io.github.bucket4j.Bucket;

/**
 * Ordered ahead of Spring Security's filter chain (default order -100) so that POST /login
 * — handled entirely inside Spring Security's UsernamePasswordAuthenticationFilter, which
 * commits a response without delegating further down the servlet filter chain — still passes
 * through this rate limiter instead of bypassing it.
 */
@Component
@Order(-200)
public class LoginRateLimitFilter implements Filter {

    private static final long MAX_TRACKED_CLIENTS = 50_000;

    private final Cache<String, Bucket> authBuckets = Caffeine.newBuilder()
            .expireAfterAccess(Duration.ofHours(2))
            .maximumSize(MAX_TRACKED_CLIENTS)
            .build();
    private final Cache<String, Bucket> providerRegisterBuckets = Caffeine.newBuilder()
            .expireAfterAccess(Duration.ofHours(6))
            .maximumSize(MAX_TRACKED_CLIENTS)
            .build();
    private final Cache<String, Bucket> apiBuckets = Caffeine.newBuilder()
            .expireAfterAccess(Duration.ofMinutes(30))
            .maximumSize(MAX_TRACKED_CLIENTS)
            .build();
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
                if (!consumeRedirect(authBuckets, clientKey + ":/login", loginBucket(), res,
                        "/login?error=rate_limit")) {
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
            Cache<String, Bucket> store,
            String key,
            Bucket prototype,
            HttpServletResponse res,
            String redirectUrl) throws IOException {

        Bucket bucket = store.get(key, k -> prototype);
        if (!bucket.tryConsume(1)) {
            res.sendRedirect(redirectUrl);
            return false;
        }
        return true;
    }

    private boolean consumeApi(
            Cache<String, Bucket> store,
            String key,
            Bucket prototype,
            HttpServletResponse res) throws IOException {

        Bucket bucket = store.get(key, k -> prototype);
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
