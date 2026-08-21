package com.scholarzim.util;

import org.springframework.security.core.Authentication;
import org.springframework.security.core.context.SecurityContextHolder;


public final class LayoutViewUtil {

    private LayoutViewUtil() {
    }

    public static boolean isAuthenticatedUser() {
        Authentication authentication = SecurityContextHolder.getContext().getAuthentication();
        return authentication != null
                && authentication.isAuthenticated()
                && !"anonymousUser".equals(authentication.getPrincipal());
    }

    public static String errorView() {
        return isAuthenticatedUser() ? "error-dashboard" : "error";
    }
}
