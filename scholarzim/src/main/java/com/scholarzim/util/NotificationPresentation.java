package com.scholarzim.util;

import java.time.Duration;
import java.time.LocalDateTime;
import java.util.Map;


public final class NotificationPresentation {

    private static final Map<String, String> ICONS = Map.ofEntries(
            Map.entry(NotificationType.APPLICATION_APPROVED, "bi-check-circle-fill"),
            Map.entry(NotificationType.APPLICATION_REJECTED, "bi-x-circle-fill"),
            Map.entry(NotificationType.APPLICATION_SUBMITTED, "bi-send-check-fill"),
            Map.entry(NotificationType.NEW_APPLICATION, "bi-inbox-fill"),
            Map.entry(NotificationType.DOCUMENTS_REQUESTED, "bi-file-earmark-plus-fill"),
            Map.entry(NotificationType.DEADLINE_REMINDER, "bi-alarm-fill"),
            Map.entry(NotificationType.NEW_OPPORTUNITY, "bi-stars"),
            Map.entry(NotificationType.MESSAGE_RECEIVED, "bi-chat-dots-fill"),
            Map.entry(NotificationType.PROFILE_INCOMPLETE, "bi-person-exclamation"),
            Map.entry(NotificationType.PROVIDER_APPLICATION, "bi-building-add"),
            Map.entry(NotificationType.PROVIDER_APPROVED, "bi-patch-check-fill"),
            Map.entry(NotificationType.PROVIDER_REJECTED, "bi-building-x"));

    private static final Map<String, String> TONES = Map.ofEntries(
            Map.entry(NotificationType.APPLICATION_APPROVED, "success"),
            Map.entry(NotificationType.APPLICATION_REJECTED, "danger"),
            Map.entry(NotificationType.APPLICATION_SUBMITTED, "primary"),
            Map.entry(NotificationType.NEW_APPLICATION, "info"),
            Map.entry(NotificationType.DOCUMENTS_REQUESTED, "warning"),
            Map.entry(NotificationType.DEADLINE_REMINDER, "warning"),
            Map.entry(NotificationType.NEW_OPPORTUNITY, "primary"),
            Map.entry(NotificationType.MESSAGE_RECEIVED, "info"),
            Map.entry(NotificationType.PROFILE_INCOMPLETE, "warning"),
            Map.entry(NotificationType.PROVIDER_APPLICATION, "secondary"),
            Map.entry(NotificationType.PROVIDER_APPROVED, "success"),
            Map.entry(NotificationType.PROVIDER_REJECTED, "danger"));

    private static final Map<String, String> LABELS = Map.ofEntries(
            Map.entry(NotificationType.APPLICATION_APPROVED, "Approved"),
            Map.entry(NotificationType.APPLICATION_REJECTED, "Rejected"),
            Map.entry(NotificationType.APPLICATION_SUBMITTED, "Submitted"),
            Map.entry(NotificationType.NEW_APPLICATION, "New application"),
            Map.entry(NotificationType.DOCUMENTS_REQUESTED, "Documents needed"),
            Map.entry(NotificationType.DEADLINE_REMINDER, "Deadline"),
            Map.entry(NotificationType.NEW_OPPORTUNITY, "New match"),
            Map.entry(NotificationType.MESSAGE_RECEIVED, "New message"),
            Map.entry(NotificationType.PROFILE_INCOMPLETE, "Profile"),
            Map.entry(NotificationType.PROVIDER_APPLICATION, "Provider signup"),
            Map.entry(NotificationType.PROVIDER_APPROVED, "Provider approved"),
            Map.entry(NotificationType.PROVIDER_REJECTED, "Provider declined"));

    private NotificationPresentation() {
    }

    public static String icon(String type) {
        return ICONS.getOrDefault(type, "bi-bell-fill");
    }

    public static String tone(String type) {
        return TONES.getOrDefault(type, "primary");
    }

    public static String label(String type) {
        if (type == null || type.isBlank()) {
            return "Update";
        }
        return LABELS.getOrDefault(type, type.replace('_', ' ').toLowerCase());
    }

    public static String relativeTime(LocalDateTime createdAt) {
        if (createdAt == null) {
            return "";
        }
        Duration duration = Duration.between(createdAt, LocalDateTime.now());
        if (duration.isNegative() || duration.toMinutes() < 1) {
            return "Just now";
        }
        long minutes = duration.toMinutes();
        if (minutes < 60) {
            return minutes + (minutes == 1 ? " minute ago" : " minutes ago");
        }
        long hours = duration.toHours();
        if (hours < 24) {
            return hours + (hours == 1 ? " hour ago" : " hours ago");
        }
        long days = duration.toDays();
        if (days < 7) {
            return days + (days == 1 ? " day ago" : " days ago");
        }
        long weeks = days / 7;
        if (weeks < 5) {
            return weeks + (weeks == 1 ? " week ago" : " weeks ago");
        }
        long months = days / 30;
        if (months < 12) {
            return months + (months == 1 ? " month ago" : " months ago");
        }
        long years = days / 365;
        return years + (years == 1 ? " year ago" : " years ago");
    }
}
