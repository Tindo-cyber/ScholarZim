package com.scholarzim.config;

import jakarta.servlet.http.HttpServletRequest;
import org.springframework.security.web.csrf.CsrfToken;
import org.springframework.web.bind.annotation.ControllerAdvice;
import org.springframework.web.bind.annotation.ModelAttribute;

/**
 * Exposes a resolved CSRF token for Thymeleaf fragments. Spring Security 6 may defer
 * token materialisation until first access — {@code token.getToken()} forces that here.
 */
@ControllerAdvice
public class CsrfModelAdvice {

    @ModelAttribute("_csrf")
    public CsrfToken csrfToken(HttpServletRequest request) {
        CsrfToken token = (CsrfToken) request.getAttribute(CsrfToken.class.getName());
        if (token == null) {
            token = (CsrfToken) request.getAttribute("_csrf");
        }
        if (token != null) {
            token.getToken();
        }
        return token;
    }
}
