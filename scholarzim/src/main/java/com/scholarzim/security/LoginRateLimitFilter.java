package com.scholarzim.security;

import io.github.bucket4j.Bandwidth;
import io.github.bucket4j.Bucket;
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

@Component
@Order(1)
public class LoginRateLimitFilter implements Filter {

    private final Map<String, Bucket> buckets = new ConcurrentHashMap<>();
    private final int limitPerMinute;

    public LoginRateLimitFilter(
            @Value("${scholarzim.rate-limit.login-per-minute:10}") int limitPerMinute) {

        this.limitPerMinute = limitPerMinute;
    }

    @Override
    public void doFilter(ServletRequest request, ServletResponse response, FilterChain chain)
            throws IOException, ServletException {

        HttpServletRequest req = (HttpServletRequest) request;
        HttpServletResponse res = (HttpServletResponse) response;

        if ("POST".equalsIgnoreCase(req.getMethod())
                && ("/login".equals(req.getServletPath()) || "/register".equals(req.getServletPath()))) {

            String key = req.getRemoteAddr() + ":" + req.getServletPath();
            Bucket bucket = buckets.computeIfAbsent(key, k -> newBucket());

            if (!bucket.tryConsume(1)) {
                res.sendError(429, "Too many attempts. Please wait a minute.");
                return;
            }
        }

        chain.doFilter(request, response);
    }

    private Bucket newBucket() {
        Bandwidth limit = Bandwidth.builder()
                .capacity(limitPerMinute)
                .refillGreedy(limitPerMinute, Duration.ofMinutes(1))
                .build();
        return Bucket.builder().addLimit(limit).build();
    }
}
