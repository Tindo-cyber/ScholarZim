package com.scholarzim.util;

import com.scholarzim.entity.Application;

import java.time.LocalDateTime;
import java.time.format.DateTimeFormatter;
import java.util.List;
import java.util.Locale;


public final class ApplicationTimeline {

    public static final int STAGE_COUNT = 5;

    public static final List<String> STAGE_LABELS = List.of(
            "Submitted",
            "Under Review",
            "Shortlisted",
            "Interview",
            "Awarded");

    private static final DateTimeFormatter DATE_FMT =
            DateTimeFormatter.ofPattern("dd MMM yyyy", Locale.ENGLISH);

    private ApplicationTimeline() {
    }

    public static int currentStageIndex(String status) {
        String normalized = normalize(status);
        return switch (normalized) {
            case ApplicationStatus.PENDING, ApplicationStatus.SUBMITTED -> 0;
            case ApplicationStatus.UNDER_REVIEW, ApplicationStatus.DOCUMENTS_REQUESTED -> 1;
            case ApplicationStatus.WAITLISTED, ApplicationStatus.SHORTLISTED -> 2;
            case ApplicationStatus.INTERVIEW -> 3;
            case ApplicationStatus.APPROVED, ApplicationStatus.AWARDED -> 4;
            case ApplicationStatus.REJECTED -> 1;
            default -> 0;
        };
    }

    public static int progressPercent(String status) {
        String normalized = normalize(status);
        if (ApplicationStatus.REJECTED.equals(normalized)) {
            return 25;
        }
        int index = currentStageIndex(status);
        return Math.min(100, Math.round((index / 4.0f) * 100));
    }

    public static boolean isRejected(String status) {
        return ApplicationStatus.REJECTED.equals(normalize(status));
    }

    public static boolean isStageComplete(String status, int stageIndex) {
        if (stageIndex < 0 || stageIndex >= STAGE_COUNT) {
            return false;
        }
        if (isRejected(status)) {
            return stageIndex < currentStageIndex(status);
        }
        return stageIndex < currentStageIndex(status);
    }

    public static boolean isStageCurrent(String status, int stageIndex) {
        return stageIndex == currentStageIndex(status);
    }

    public static boolean isStageUpcoming(String status, int stageIndex) {
        if (isRejected(status)) {
            return stageIndex > currentStageIndex(status);
        }
        return stageIndex > currentStageIndex(status);
    }

    public static String stageDateLabel(Application application, int stageIndex) {
        if (application == null || stageIndex < 0 || stageIndex >= STAGE_COUNT) {
            return "—";
        }
        String status = application.getApplicationStatus();
        LocalDateTime submittedAt = application.getSubmittedAt();
        int current = currentStageIndex(status);

        if (stageIndex == 0 && submittedAt != null) {
            return submittedAt.format(DATE_FMT);
        }
        if (isStageCurrent(status, stageIndex) && !isRejected(status)) {
            return "In progress";
        }
        if (isStageComplete(status, stageIndex) && stageIndex > 0) {
            return submittedAt != null ? submittedAt.format(DATE_FMT) : "Completed";
        }
        if (stageIndex == 4 && current == 4 && submittedAt != null) {
            return submittedAt.format(DATE_FMT);
        }
        if (isRejected(status) && isStageCurrent(status, stageIndex)) {
            return "Not progressed";
        }
        return "—";
    }

    public static String trackerBadgeClass(String status) {
        String normalized = normalize(status);
        return switch (normalized) {
            case ApplicationStatus.APPROVED, ApplicationStatus.AWARDED -> "sz-app-badge--awarded";
            case ApplicationStatus.REJECTED -> "sz-app-badge--rejected";
            case ApplicationStatus.INTERVIEW -> "sz-app-badge--interview";
            case ApplicationStatus.WAITLISTED, ApplicationStatus.SHORTLISTED -> "sz-app-badge--shortlisted";
            case ApplicationStatus.UNDER_REVIEW, ApplicationStatus.DOCUMENTS_REQUESTED -> "sz-app-badge--review";
            case ApplicationStatus.SUBMITTED, ApplicationStatus.PENDING -> "sz-app-badge--submitted";
            default -> "sz-app-badge--default";
        };
    }

    public static String trackerLabel(String status) {
        String normalized = normalize(status);
        return switch (normalized) {
            case ApplicationStatus.APPROVED, ApplicationStatus.AWARDED -> "Awarded";
            case ApplicationStatus.WAITLISTED, ApplicationStatus.SHORTLISTED -> "Shortlisted";
            case ApplicationStatus.INTERVIEW -> "Interview";
            case ApplicationStatus.UNDER_REVIEW -> "Under review";
            case ApplicationStatus.DOCUMENTS_REQUESTED -> "Documents requested";
            case ApplicationStatus.SUBMITTED -> "Submitted";
            case ApplicationStatus.PENDING -> "Submitted";
            case ApplicationStatus.REJECTED -> "Rejected";
            default -> ApplicationStatus.displayLabel(status);
        };
    }

    public static String stepClass(String status, int stageIndex) {
        if (isStageComplete(status, stageIndex)) {
            return "sz-app-timeline__step--complete";
        }
        if (isStageCurrent(status, stageIndex)) {
            return isRejected(status)
                    ? "sz-app-timeline__step--rejected"
                    : "sz-app-timeline__step--current";
        }
        return "sz-app-timeline__step--upcoming";
    }

    public static String stepIconClass(String status, int stageIndex) {
        if (isStageComplete(status, stageIndex)) {
            return "bi-check-lg";
        }
        if (isStageCurrent(status, stageIndex)) {
            return isRejected(status) ? "bi-x-lg" : "bi-circle-fill";
        }
        return "bi-circle";
    }

    private static String normalize(String status) {
        return status == null ? "" : status.trim().toUpperCase(Locale.ROOT);
    }
}
