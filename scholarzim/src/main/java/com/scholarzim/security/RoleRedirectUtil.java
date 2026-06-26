package com.scholarzim.security;

import org.springframework.security.core.GrantedAuthority;

import java.util.Collection;

public final class RoleRedirectUtil {

    private RoleRedirectUtil() {
    }

    public static String getDashboardUrl(
            Collection<? extends GrantedAuthority> authorities) {

        for (GrantedAuthority authority : authorities) {
            switch (authority.getAuthority()) {
                case "ROLE_APPLICANT":
                    return "/applicant/dashboard";
                case "ROLE_PROVIDER":
                    return "/provider/dashboard";
                case "ROLE_ADMIN":
                    return "/admin/dashboard";
                default:
                    break;
            }
        }

        return "/login";
    }
}
