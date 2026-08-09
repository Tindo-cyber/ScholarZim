package com.scholarzim.security;

import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import org.springframework.security.web.header.HeaderWriter;

import java.security.SecureRandom;
import java.util.Base64;

/**
 * Generates a fresh per-request nonce and stores it as a request attribute so Thymeleaf
 * templates can stamp it on the handful of inline &lt;script&gt; blocks that need
 * server-rendered data, letting the CSP script-src drop 'unsafe-inline'.
 */
public class CspNonceHeaderWriter implements HeaderWriter {

    public static final String REQUEST_ATTRIBUTE = "cspNonce";

    private final SecureRandom secureRandom = new SecureRandom();
    private final Base64.Encoder encoder = Base64.getUrlEncoder().withoutPadding();

    @Override
    public void writeHeaders(HttpServletRequest request, HttpServletResponse response) {
        String nonce = generateNonce();
        request.setAttribute(REQUEST_ATTRIBUTE, nonce);
        response.setHeader("Content-Security-Policy",
                "default-src 'self'; "
                        + "script-src 'self' 'nonce-" + nonce + "' https://cdn.jsdelivr.net; "
                        + "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; "
                        + "font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com; "
                        + "img-src 'self' data:; connect-src 'self'");
    }

    private String generateNonce() {
        byte[] bytes = new byte[16];
        secureRandom.nextBytes(bytes);
        return encoder.encodeToString(bytes);
    }
}
