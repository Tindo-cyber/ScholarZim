package com.scholarzim.util;

import java.util.LinkedHashMap;
import java.util.Map;


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

    private static final Map<String, String> LABELS = new LinkedHashMap<>();

    static {
        LABELS.put(SUBMITTED, "Submitted");
        LABELS.put(UNDER_REVIEW, "Under review");
        LABELS.put(DOCUMENTS_REQUESTED, "Documents requested");
        LABELS.put(APPROVED, "Approved");
        LABELS.put(REJECTED, "Rejected");
        LABELS.put(WAITLISTED, "Waitlisted");
        LABELS.put(PENDING, "Pending");
    }

    public static boolean isTerminal(String status) {
        return APPROVED.equals(status) || REJECTED.equals(status);
    }

    public static String displayLabel(String status) {
        if (status == null || status.isBlank()) {
            return "Unknown";
        }
        return LABELS.getOrDefault(status, status.replace('_', ' ').toLowerCase());
    }
}
