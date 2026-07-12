package com.scholarzim.security;

import jakarta.servlet.*;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.core.annotation.Order;
import org.springframework.stereotype.Component;

import java.io.IOException;
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
                if (!consume(providerRegisterBuckets, clientKey + ":provider-register",
                        providerRegisterBucket(), res,
                        "Too many provider applications. Please try again later.")) {
                    return;
                }
            } else if ("/forgot-password".equals(path)) {
                if (!consume(authBuckets, clientKey + ":forgot-password",
                        forgotPasswordBucket(), res,
                        "Too many reset requests. Please try again later.")) {
                    return;
                }
            } else if ("/login".equals(path) || "/register".equals(path)) {
                if (!consume(authBuckets, clientKey + ":" + path,
                        loginBucket(), res,
                        "Too many attempts. Please wait a minute.")) {
                    return;
                }
            }
        }

        if (path.startsWith("/api/") && !consume(apiBuckets, clientKey + ":api",
                apiBucket(), res, "API rate limit exceeded. Please slow down.")) {
            return;
        }

        chain.doFilter(request, response);
    }

    private boolean consume(
            Map<String, Bucket> store,
            String key,
            Bucket prototype,
            HttpServletResponse res,
            String message) throws IOException {

        Bucket bucket = store.computeIfAbsent(key, k -> prototype);
        if (!bucket.tryConsume(1)) {
            res.sendError(429, message);
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
