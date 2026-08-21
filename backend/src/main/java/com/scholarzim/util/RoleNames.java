package com.scholarzim.util;


public final class RoleNames {

    public static final String APPLICANT = "ROLE_APPLICANT";
    public static final String PROVIDER = "ROLE_PROVIDER";
    public static final String ADMIN = "ROLE_ADMIN";

    private RoleNames() {
    }

    public static String displayLabel(String roleName) {
        if (roleName == null) {
            return "User";
        }
        return switch (roleName) {
            case APPLICANT -> "Student";
            case PROVIDER -> "Provider";
            case ADMIN -> "Admin";
            default -> roleName.replace("ROLE_", "").toLowerCase();
        };
    }
}
