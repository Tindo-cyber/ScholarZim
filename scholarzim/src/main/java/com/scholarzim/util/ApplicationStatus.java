package com.scholarzim.util;

public final class ApplicationStatus {

    private ApplicationStatus() {
    }

    public static final String SUBMITTED = "SUBMITTED";
    public static final String UNDER_REVIEW = "UNDER_REVIEW";
    public static final String DOCUMENTS_REQUESTED = "DOCUMENTS_REQUESTED";
    public static final String APPROVED = "APPROVED";
    public static final String REJECTED = "REJECTED";
    public static final String WAITLISTED = "WAITLISTED";
    public static final String PENDING = "PENDING"; // legacy

    public static boolean isTerminal(String status) {
        return APPROVED.equals(status) || REJECTED.equals(status);
    }
}
