package com.scholarzim.config;

import org.springframework.security.web.csrf.CsrfToken;
import org.springframework.web.bind.annotation.ControllerAdvice;
import org.springframework.web.bind.annotation.ModelAttribute;

/**
 * Forces resolution of Spring Security's (possibly deferred) CSRF token so Thymeleaf
 * can safely use {@code ${_csrf.parameterName}} / {@code ${_csrf.token}} in fragments.
 */
@ControllerAdvice
public class CsrfModelAdvice {

    @ModelAttribute("_csrf")
    public CsrfToken csrfToken(CsrfToken token) {
        return token;
    }
}
