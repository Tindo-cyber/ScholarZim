package com.scholarzim.util;

import com.scholarzim.entity.Notification;
import com.scholarzim.entity.User;

import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;


public final class NotificationCenterSupport {

    public static final int PAGE_SIZE = 10;
    public static final List<String> CATEGORIES =
            List.of("APPLICATIONS", "SCHOLARSHIPS", "SYSTEM");

    private NotificationCenterSupport() {
    }

    public record NotificationPage(
            List<Notification> notifications,
            int filteredTotal,
            int totalAll,
            int totalPages,
            int currentPage,
            long filteredUnread,
            Map<String, Long> categoryCounts) {
    }

    public static NotificationPage buildPage(
            List<Notification> all,
            String query,
            String category,
            String readFilter,
            int page) {

        List<Notification> source = all == null ? List.of() : all;
        Map<String, Long> counts = categoryCounts(source);

        List<Notification> filtered = source.stream()
                .filter(notification -> matchesQuery(notification, query))
                .filter(notification -> matchesCategory(notification, category))
                .filter(notification -> matchesReadFilter(notification, readFilter))
                .toList();

        int filteredTotal = filtered.size();
        int totalPages = filteredTotal == 0
                ? 0
                : (int) Math.ceil((double) filteredTotal / PAGE_SIZE);
        int safePage = Math.max(0, page);
        if (totalPages > 0 && safePage >= totalPages) {
            safePage = totalPages - 1;
        }

        int fromIndex = safePage * PAGE_SIZE;
        int toIndex = Math.min(fromIndex + PAGE_SIZE, filteredTotal);
        List<Notification> pageContent = filteredTotal == 0
                ? List.of()
                : filtered.subList(fromIndex, toIndex);

        return new NotificationPage(
                pageContent,
                filteredTotal,
                source.size(),
                totalPages,
                safePage,
                filtered.stream().filter(Notification::isUnread).count(),
                counts);
    }

    public static boolean emailAllowedForType(User user, String type) {
        if (user == null) {
            return false;
        }
        return switch (category(type)) {
            case "APPLICATIONS" -> user.isEmailNotifyApplications();
            case "SCHOLARSHIPS" -> user.isEmailNotifyScholarships();
            default -> user.isEmailNotifySystem();
        };
    }

    public static String category(String type) {
        String normalized = normalize(type);
        if (normalized.startsWith("APPLICATION_")
                || NotificationType.NEW_APPLICATION.equals(normalized)
                || NotificationType.DOCUMENTS_REQUESTED.equals(normalized)) {
            return "APPLICATIONS";
        }
        if (NotificationType.NEW_OPPORTUNITY.equals(normalized)
                || NotificationType.DEADLINE_REMINDER.equals(normalized)
                || normalized.startsWith("SCHOLARSHIP_")) {
            return "SCHOLARSHIPS";
        }
        return "SYSTEM";
    }

    public static String categoryLabel(String category) {
        return switch (normalize(category)) {
            case "APPLICATIONS" -> "Applications";
            case "SCHOLARSHIPS" -> "Scholarships";
            default -> "System";
        };
    }

    public static String categoryIcon(String category) {
        return switch (normalize(category)) {
            case "APPLICATIONS" -> "bi-file-earmark-check";
            case "SCHOLARSHIPS" -> "bi-mortarboard";
            default -> "bi-gear";
        };
    }

    public static String categoryTone(String category) {
        return switch (normalize(category)) {
            case "APPLICATIONS" -> "applications";
            case "SCHOLARSHIPS" -> "scholarships";
            default -> "system";
        };
    }

    private static Map<String, Long> categoryCounts(List<Notification> notifications) {
        Map<String, Long> counts = new LinkedHashMap<>();
        CATEGORIES.forEach(category -> counts.put(category, 0L));
        notifications.forEach(notification -> {
            String category = category(notification.getType());
            counts.put(category, counts.getOrDefault(category, 0L) + 1);
        });
        return counts;
    }

    private static boolean matchesQuery(Notification notification, String query) {
        if (query == null || query.isBlank()) {
            return true;
        }
        String needle = query.trim().toLowerCase(Locale.ROOT);
        String message = notification.getMessage() == null
                ? ""
                : notification.getMessage().toLowerCase(Locale.ROOT);
        String label = NotificationPresentation.label(notification.getType())
                .toLowerCase(Locale.ROOT);
        return message.contains(needle) || label.contains(needle);
    }

    private static boolean matchesCategory(Notification notification, String category) {
        return category == null
                || category.isBlank()
                || "ALL".equalsIgnoreCase(category)
                || category(notification.getType()).equalsIgnoreCase(category);
    }

    private static boolean matchesReadFilter(Notification notification, String readFilter) {
        if (readFilter == null || readFilter.isBlank() || "ALL".equalsIgnoreCase(readFilter)) {
            return true;
        }
        if ("UNREAD".equalsIgnoreCase(readFilter)) {
            return notification.isUnread();
        }
        if ("READ".equalsIgnoreCase(readFilter)) {
            return notification.isRead();
        }
        return true;
    }

    private static String normalize(String value) {
        return value == null ? "" : value.trim().toUpperCase(Locale.ROOT);
    }
}
