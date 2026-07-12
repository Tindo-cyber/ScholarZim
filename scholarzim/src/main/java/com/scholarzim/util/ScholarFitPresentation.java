package com.scholarzim.util;

public final class ScholarFitPresentation {

    private ScholarFitPresentation() {
    }

    public static String confidenceBadgeClass(String level) {
        if (level == null) {
            return "sz-scholarfit-confidence--medium";
        }
        return switch (level.toUpperCase()) {
            case "HIGH" -> "sz-scholarfit-confidence--high";
            case "LOW" -> "sz-scholarfit-confidence--low";
            default -> "sz-scholarfit-confidence--medium";
        };
    }

    public static String matchRingClass(int matchScore) {
        if (matchScore >= 80) {
            return "sz-scholarfit-match--excellent";
        }
        if (matchScore >= 60) {
            return "sz-scholarfit-match--good";
        }
        return "sz-scholarfit-match--fair";
    }
}
