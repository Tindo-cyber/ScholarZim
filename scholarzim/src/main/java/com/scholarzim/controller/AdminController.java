package com.scholarzim.controller;

import com.scholarzim.dto.AdminDashboardDTO;
import com.scholarzim.exception.AdminOperationException;
import com.scholarzim.exception.ResourceNotFoundException;
import com.scholarzim.service.AdminSearchService;
import com.scholarzim.service.AdminUserService;
import com.scholarzim.service.AnalyticsService;
import com.scholarzim.service.AuditLogService;
import com.scholarzim.util.ChartMonths;
import org.springframework.core.io.Resource;
import org.springframework.http.HttpHeaders;
import org.springframework.http.MediaType;
import org.springframework.http.ResponseEntity;
import org.springframework.lang.NonNull;
import org.springframework.security.core.Authentication;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.servlet.mvc.support.RedirectAttributes;

import java.util.List;


@Controller
public class AdminController {

    private static final int PAGE_SIZE = 10;

    private final AnalyticsService analyticsService;
    private final AdminUserService adminUserService;
    private final AuditLogService auditLogService;
    private final AdminSearchService adminSearchService;

    public AdminController(
            AnalyticsService analyticsService,
            AdminUserService adminUserService,
            AuditLogService auditLogService,
            AdminSearchService adminSearchService) {

        this.analyticsService = analyticsService;
        this.adminUserService = adminUserService;
        this.auditLogService = auditLogService;
        this.adminSearchService = adminSearchService;
    }

    @GetMapping("/admin/dashboard")
    public String adminDashboard(
            @RequestParam(defaultValue = "0") int applicantPage,
            @RequestParam(defaultValue = "0") int providerPage,
            Model model) {

        model.addAttribute("stats", analyticsService.getDashboardStats());
        model.addAttribute("recentActivity", analyticsService.getRecentActivity(8));
        model.addAttribute("applicants", adminUserService.listApplicants(applicantPage, PAGE_SIZE));
        model.addAttribute("providers", adminUserService.listProviders(providerPage, PAGE_SIZE));
        model.addAttribute("pendingProviders", adminUserService.listPendingProviders());
        model.addAttribute("applicantPage", applicantPage);
        model.addAttribute("providerPage", providerPage);

        int months = 6;
        model.addAttribute("monthlyCounts", analyticsService.getMonthlyApplicationCounts(months));
        model.addAttribute("monthLabels", ChartMonths.labelsForLastMonths(months));

        model.addAttribute("topProviders", analyticsService.getTopProviders(5));
        model.addAttribute("mostAppliedOpportunities",
                analyticsService.getMostAppliedOpportunities(5));

        return "admin/dashboard";
    }

    @GetMapping("/admin/reports")
    public String reportsHub() {
        return "admin/reports";
    }

    @GetMapping("/admin/analytics")
    public String analytics(
            @RequestParam(defaultValue = "12") int months,
            Model model) {

        int range = Math.min(Math.max(months, 3), 24);
        AdminDashboardDTO stats = analyticsService.getDashboardStats();

        model.addAttribute("stats", stats);
        model.addAttribute("months", range);

        List<Long> monthlyCounts = analyticsService.getMonthlyApplicationCounts(range);
        model.addAttribute("monthlyCounts", monthlyCounts);
        model.addAttribute("monthLabels", ChartMonths.labelsForLastMonths(range));

        model.addAttribute("statusBreakdown", analyticsService.getApplicationStatusBreakdown());
        model.addAttribute("userRoles", analyticsService.getUserRoleBreakdown());
        model.addAttribute("scholarshipAvailability", analyticsService.getScholarshipAvailabilityBreakdown());
        model.addAttribute("topProviders", analyticsService.getTopProviders(10));
        model.addAttribute("mostAppliedOpportunities", analyticsService.getMostAppliedOpportunities(10));

        long profileRate = stats.getTotalApplicants() == 0 ? 0
                : (int) Math.round(100.0 * stats.getCompleteProfiles() / stats.getTotalApplicants());
        model.addAttribute("profileCoverageRate", profileRate);

        long monthlyTotal = monthlyCounts.stream().mapToLong(Long::longValue).sum();
        model.addAttribute("monthlyAverage", range == 0 ? 0 : Math.round((double) monthlyTotal / range));

        return "admin/analytics";
    }

    @GetMapping("/admin/audit-log")
    public String auditLog(
            @RequestParam(required = false) String q,
            @RequestParam(required = false) String action,
            @RequestParam(defaultValue = "0") int page,
            Model model) {

        model.addAttribute("auditPage", auditLogService.search(q, action, page, 25));
        model.addAttribute("query", q != null ? q : "");
        model.addAttribute("actionFilter", action != null ? action : "");
        model.addAttribute("auditActions", auditLogService.listDistinctActions());
        model.addAttribute("page", page);
        return "admin/audit-log";
    }

    @GetMapping("/admin/search")
    public String globalSearch(
            @RequestParam(required = false) String q,
            Model model) {

        String query = q != null ? q : "";
        model.addAttribute("query", query);
        model.addAttribute("results", adminSearchService.search(query));
        return "admin/search";
    }

    @PostMapping("/admin/users/applicants/{id}/delete")
    public String deleteApplicant(
            @PathVariable Long id,
            @NonNull Authentication authentication,
            RedirectAttributes redirect) {

        return handleUserAction(() ->
                adminUserService.deleteApplicant(id, authentication.getName()),
                redirect, "Applicant account deleted successfully.");
    }

    @PostMapping("/admin/users/providers/{id}/delete")
    public String deleteProvider(
            @PathVariable Long id,
            @NonNull Authentication authentication,
            RedirectAttributes redirect) {

        return handleUserAction(() ->
                adminUserService.deleteProvider(id, authentication.getName()),
                redirect, "Provider account deleted successfully.");
    }

    @PostMapping("/admin/users/{id}/suspend")
    public String suspendUser(
            @PathVariable Long id,
            @NonNull Authentication authentication,
            RedirectAttributes redirect) {

        return handleUserAction(() ->
                adminUserService.suspendUser(id, authentication.getName()),
                redirect, "User suspended.");
    }

    @PostMapping("/admin/users/{id}/reactivate")
    public String reactivateUser(
            @PathVariable Long id,
            @NonNull Authentication authentication,
            RedirectAttributes redirect) {

        return handleUserAction(() ->
                adminUserService.reactivateUser(id, authentication.getName()),
                redirect, "User reactivated.");
    }

    @PostMapping("/admin/users/providers/{id}/approve")
    public String approveProvider(
            @PathVariable Long id,
            @NonNull Authentication authentication,
            RedirectAttributes redirect) {

        return handleUserAction(() ->
                adminUserService.approveProvider(id, authentication.getName()),
                redirect, "Provider approved.");
    }

    @PostMapping("/admin/users/providers/{id}/reject")
    public String rejectProvider(
            @PathVariable Long id,
            @RequestParam String reason,
            @NonNull Authentication authentication,
            RedirectAttributes redirect) {

        return handleUserAction(() ->
                adminUserService.rejectProvider(id, authentication.getName(), reason),
                redirect, "Provider application rejected.");
    }

    @GetMapping("/admin/providers/{userId}/certificate")
    public ResponseEntity<Resource> downloadProviderCertificate(
            @PathVariable Long userId,
            @NonNull Authentication authentication) {

        try {
            var file = adminUserService.loadProviderCertificate(userId, authentication.getName());
            String filename = file.displayName() != null ? file.displayName() : "registration-certificate.pdf";
            return ResponseEntity.ok()
                    .header(HttpHeaders.CONTENT_DISPOSITION, "inline; filename=\"" + filename + "\"")
                    .contentType(MediaType.parseMediaType(file.contentType()))
                    .body(file.resource());
        } catch (ResourceNotFoundException ex) {
            return ResponseEntity.notFound().build();
        } catch (AdminOperationException ex) {
            return ResponseEntity.badRequest().build();
        }
    }

    private String handleUserAction(Runnable action, RedirectAttributes redirect, String success) {

        try {
            action.run();
            redirect.addFlashAttribute("successMessage", success);
        } catch (AdminOperationException ex) {
            redirect.addFlashAttribute("errorMessage", ex.getMessage());
        } catch (ResourceNotFoundException ex) {
            redirect.addFlashAttribute("errorMessage", "User not found.");
        }

        return "redirect:/admin/dashboard#user-management";
    }
}
