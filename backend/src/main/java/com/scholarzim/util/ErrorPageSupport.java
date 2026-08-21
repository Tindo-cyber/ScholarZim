package com.scholarzim.util;

import com.scholarzim.security.RoleRedirectUtil;
import org.springframework.security.core.Authentication;
import org.springframework.security.core.context.SecurityContextHolder;

import java.util.Map;


public final class ErrorPageSupport {

    public static final String NOT_FOUND = "not-found";
    public static final String SERVER_ERROR = "server-error";
    public static final String NETWORK = "network";
    public static final String PERMISSION_DENIED = "permission-denied";
    public static final String NO_DATA = "no-data";
    public static final String RATE_LIMITED = "rate-limited";

    private static final String SUPPORT_EMAIL = "support@scholarzim.co.zw";

    private static final Map<String, String> TITLES = Map.of(
            NOT_FOUND, "Page not found",
            SERVER_ERROR, "Something went wrong",
            NETWORK, "Connection problem",
            PERMISSION_DENIED, "Permission denied",
            NO_DATA, "Nothing here yet",
            RATE_LIMITED, "Too many attempts");

    private static final Map<String, String> MESSAGES = Map.of(
            NOT_FOUND,
            "We couldn't find the page you're looking for. It may have moved, expired, or the link might be incorrect.",
            SERVER_ERROR,
            "We're having trouble completing your request right now. Please try again in a moment.",
            NETWORK,
            "ScholarZim can't reach the server. Check your internet connection and try again.",
            PERMISSION_DENIED,
            "You don't have access to this area. Sign in with the right account or return to your dashboard.",
            NO_DATA,
            "There's nothing to show here yet. Check back later or explore scholarships to get started.",
            RATE_LIMITED,
            "You've made too many requests in a short time. Please wait a minute and try again.");

    private ErrorPageSupport() {
    }

    public static String resolveType(Integer status) {
        if (status == null) {
            return SERVER_ERROR;
        }
        return switch (status) {
            case 403 -> PERMISSION_DENIED;
            case 404 -> NOT_FOUND;
            case 429 -> RATE_LIMITED;
            default -> SERVER_ERROR;
        };
    }

    public static String title(String type) {
        return TITLES.getOrDefault(normalize(type), TITLES.get(SERVER_ERROR));
    }

    public static String message(String type) {
        return MESSAGES.getOrDefault(normalize(type), MESSAGES.get(SERVER_ERROR));
    }

    public static String homeUrl() {
        if (!LayoutViewUtil.isAuthenticatedUser()) {
            return "/";
        }
        Authentication authentication = SecurityContextHolder.getContext().getAuthentication();
        if (authentication == null) {
            return "/";
        }
        return RoleRedirectUtil.getDashboardUrl(authentication.getAuthorities());
    }

    public static String supportUrl() {
        return "mailto:" + SUPPORT_EMAIL + "?subject=ScholarZim%20Support";
    }

    public static String supportLabel() {
        return "Contact support";
    }

    private static String normalize(String type) {
        return type != null ? type : SERVER_ERROR;
    }
}
