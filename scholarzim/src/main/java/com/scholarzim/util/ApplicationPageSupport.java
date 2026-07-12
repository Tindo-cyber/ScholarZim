package com.scholarzim.util;

import com.scholarzim.entity.Application;

import java.util.Comparator;
import java.util.List;
import java.util.Locale;
import java.util.Set;


public final class ApplicationPageSupport {

    public static final int PAGE_SIZE = 10;

    private static final Set<String> PENDING_STATUSES = Set.of(
            "PENDING", "SUBMITTED", "UNDER_REVIEW", "DOCUMENTS_REQUESTED", "WAITLISTED");

    private ApplicationPageSupport() {
    }

    public record ApplicationPageResult(
            List<Application> applications,
            int filteredTotal,
            int totalAll,
            int approvedCount,
            int pendingCount,
            int rejectedCount,
            int totalPages,
            int currentPage) {
    }

    public static ApplicationPageResult buildPage(
            List<Application> all,
            String query,
            String statusFilter,
            int page) {

        List<Application> sorted = all.stream()
                .sorted(Comparator.comparing(
                        Application::getSubmittedAt,
                        Comparator.nullsLast(Comparator.reverseOrder())))
                .toList();

        int totalAll = sorted.size();
        int approvedCount = countByOutcome(sorted, "APPROVED");
        int rejectedCount = countByOutcome(sorted, "REJECTED");
        int pendingCount = (int) sorted.stream()
                .filter(app -> PENDING_STATUSES.contains(normalizeStatus(app.getApplicationStatus())))
                .count();

        List<Application> filtered = sorted.stream()
                .filter(app -> matchesQuery(app, query))
                .filter(app -> matchesStatus(app, statusFilter))
                .toList();

        int filteredTotal = filtered.size();
        int totalPages = filteredTotal == 0 ? 0 : (int) Math.ceil((double) filteredTotal / PAGE_SIZE);
        int safePage = Math.max(0, page);
        if (totalPages > 0 && safePage >= totalPages) {
            safePage = totalPages - 1;
        }

        int fromIndex = safePage * PAGE_SIZE;
        int toIndex = Math.min(fromIndex + PAGE_SIZE, filteredTotal);
        List<Application> pageContent = filteredTotal == 0
                ? List.of()
                : filtered.subList(fromIndex, toIndex);

        return new ApplicationPageResult(
                pageContent,
                filteredTotal,
                totalAll,
                approvedCount,
                pendingCount,
                rejectedCount,
                totalPages,
                safePage);
    }

    private static int countByOutcome(List<Application> apps, String status) {
        return (int) apps.stream()
                .filter(app -> status.equals(normalizeStatus(app.getApplicationStatus())))
                .count();
    }

    private static boolean matchesQuery(Application app, String query) {
        if (query == null || query.isBlank()) {
            return true;
        }
        String needle = query.trim().toLowerCase(Locale.ROOT);
        String title = app.getOpportunity() != null && app.getOpportunity().getTitle() != null
                ? app.getOpportunity().getTitle().toLowerCase(Locale.ROOT)
                : "";
        String provider = app.getOpportunity() != null && app.getOpportunity().getProviderName() != null
                ? app.getOpportunity().getProviderName().toLowerCase(Locale.ROOT)
                : "";
        return title.contains(needle) || provider.contains(needle);
    }

    private static boolean matchesStatus(Application app, String statusFilter) {
        if (statusFilter == null || statusFilter.isBlank() || "ALL".equalsIgnoreCase(statusFilter)) {
            return true;
        }
        String status = normalizeStatus(app.getApplicationStatus());
        if ("PENDING".equalsIgnoreCase(statusFilter)) {
            return PENDING_STATUSES.contains(status);
        }
        return statusFilter.equalsIgnoreCase(status);
    }

    private static String normalizeStatus(String status) {
        return status == null ? "" : status.trim().toUpperCase(Locale.ROOT);
    }
}
