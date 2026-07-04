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

    private final Map<String, Bucket> buckets = new ConcurrentHashMap<>();
    private final Map<String, Bucket> providerRegisterBuckets = new ConcurrentHashMap<>();
    private final int limitPerMinute;
    private final int providerRegisterPerHour;

    public LoginRateLimitFilter(
            @Value("${scholarzim.rate-limit.login-per-minute:10}") int limitPerMinute,
            @Value("${scholarzim.rate-limit.provider-register-per-hour:5}") int providerRegisterPerHour) {

        this.limitPerMinute = limitPerMinute;
        this.providerRegisterPerHour = providerRegisterPerHour;
    }

    @Override
    public void doFilter(ServletRequest request, ServletResponse response, FilterChain chain)
            throws IOException, ServletException {

        HttpServletRequest req = (HttpServletRequest) request;
        HttpServletResponse res = (HttpServletResponse) response;

        if ("POST".equalsIgnoreCase(req.getMethod())
                && "/register/provider".equals(req.getServletPath())) {

            String key = req.getRemoteAddr() + ":provider-register";
            Bucket bucket = providerRegisterBuckets.computeIfAbsent(key, k -> providerRegisterBucket());
            if (!bucket.tryConsume(1)) {
                res.sendError(429, "Too many provider applications. Please try again later.");
                return;
            }
        }

        if ("POST".equalsIgnoreCase(req.getMethod())
                && ("/login".equals(req.getServletPath()) || "/register".equals(req.getServletPath()))) {

            String key = req.getRemoteAddr() + ":" + req.getServletPath();
            Bucket bucket = buckets.computeIfAbsent(key, k -> loginBucket());

            if (!bucket.tryConsume(1)) {
                res.sendError(429, "Too many attempts. Please wait a minute.");
                return;
            }
        }

        chain.doFilter(request, response);
    }

    private Bucket loginBucket() {
        Bandwidth limit = Bandwidth.builder()
                .capacity(limitPerMinute)
                .refillGreedy(limitPerMinute, Duration.ofMinutes(1))
                .build();
        return Bucket.builder().addLimit(limit).build();
    }

    private Bucket providerRegisterBucket() {
        Bandwidth limit = Bandwidth.builder()
                .capacity(providerRegisterPerHour)
                .refillGreedy(providerRegisterPerHour, Duration.ofHours(1))
                .build();
        return Bucket.builder().addLimit(limit).build();
    }
}
