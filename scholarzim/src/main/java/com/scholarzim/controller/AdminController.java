package com.scholarzim.controller;

import com.scholarzim.dto.AdminDashboardDTO;
import com.scholarzim.dto.AdminUserViewDTO;
import com.scholarzim.dto.AuditActivityDTO;
import com.scholarzim.dto.ChartData;
import com.scholarzim.dto.PageResult;
import com.scholarzim.dto.RecentUserDTO;
import com.scholarzim.dto.StoredFileResource;
import com.scholarzim.exception.AdminOperationException;
import com.scholarzim.exception.ResourceNotFoundException;
import com.scholarzim.service.AdminSearchService;
import com.scholarzim.service.AdminUserService;
import com.scholarzim.service.AnalyticsService;
import com.scholarzim.service.AuditLogService;
import com.scholarzim.util.ChartMonths;
import com.scholarzim.util.SoftLoad;
import lombok.extern.slf4j.Slf4j;
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

import java.util.Collections;
import java.util.List;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.function.Supplier;
import java.util.stream.IntStream;


@Slf4j
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

        AtomicBoolean loadFailed = new AtomicBoolean(false);

        model.addAttribute("stats", soft("dashboard stats", new AdminDashboardDTO(),
                analyticsService::getDashboardStats, loadFailed));
        model.addAttribute("recentActivity", soft("recent activity", List.<AuditActivityDTO>of(),
                () -> analyticsService.getRecentActivity(8), loadFailed));
        model.addAttribute("applicants", soft("applicants list",
                emptyPage(applicantPage),
                () -> adminUserService.listApplicants(applicantPage, PAGE_SIZE), loadFailed));
        model.addAttribute("providers", soft("providers list",
                emptyPage(providerPage),
                () -> adminUserService.listProviders(providerPage, PAGE_SIZE), loadFailed));
        model.addAttribute("pendingProviders", soft("pending providers",
                List.<AdminUserViewDTO>of(),
                adminUserService::listPendingProviders, loadFailed));
        model.addAttribute("applicantPage", applicantPage);
        model.addAttribute("providerPage", providerPage);

        int months = 6;
        model.addAttribute("monthlyCounts", soft("monthly counts",
                zeroSeries(months),
                () -> analyticsService.getMonthlyApplicationCounts(months), loadFailed));
        model.addAttribute("monthLabels", ChartMonths.labelsForLastMonths(months));

        model.addAttribute("topProviders", soft("top providers", new ChartData(),
                () -> analyticsService.getTopProviders(5), loadFailed));
        model.addAttribute("mostAppliedOpportunities", soft("most applied opportunities", new ChartData(),
                () -> analyticsService.getMostAppliedOpportunities(5), loadFailed));
        model.addAttribute("loadFailed", loadFailed.get());

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
        AdminDashboardDTO stats = soft("analytics stats", new AdminDashboardDTO(),
                analyticsService::getDashboardStats);

        model.addAttribute("stats", stats);
        model.addAttribute("months", range);

        List<Long> monthlyCounts = soft("analytics monthly counts",
                zeroSeries(range),
                () -> analyticsService.getMonthlyApplicationCounts(range));
        model.addAttribute("monthlyCounts", monthlyCounts);
        model.addAttribute("monthLabels", ChartMonths.labelsForLastMonths(range));

        model.addAttribute("statusBreakdown", soft("status breakdown", new ChartData(),
                analyticsService::getApplicationStatusBreakdown));
        model.addAttribute("userRoles", soft("user roles", new ChartData(),
                analyticsService::getUserRoleBreakdown));
        model.addAttribute("scholarshipAvailability", soft("scholarship availability", new ChartData(),
                analyticsService::getScholarshipAvailabilityBreakdown));
        model.addAttribute("topProviders", soft("analytics top providers", new ChartData(),
                () -> analyticsService.getTopProviders(10)));
        model.addAttribute("mostAppliedOpportunities", soft("analytics most applied", new ChartData(),
                () -> analyticsService.getMostAppliedOpportunities(10)));
        model.addAttribute("mostViewedOpportunities", soft("most viewed", new ChartData(),
                () -> analyticsService.getMostViewedOpportunities(8)));
        model.addAttribute("monthlyStudents", soft("monthly students", zeroSeries(range),
                () -> analyticsService.getMonthlyStudentRegistrations(range)));
        model.addAttribute("monthlyProviders", soft("monthly providers", zeroSeries(range),
                () -> analyticsService.getMonthlyProviderRegistrations(range)));
        model.addAttribute("monthlyScholarships", soft("monthly scholarships", zeroSeries(range),
                () -> analyticsService.getMonthlyScholarshipCounts(range)));
        model.addAttribute("recentActivity", soft("analytics recent activity",
                List.<AuditActivityDTO>of(),
                () -> analyticsService.getRecentActivity(10)));
        model.addAttribute("recentUsers", soft("recent users", List.<RecentUserDTO>of(),
                () -> analyticsService.getRecentUsers(8)));
        model.addAttribute("pendingProviders", soft("analytics pending providers",
                List.<AdminUserViewDTO>of(),
                adminUserService::listPendingProviders));

        long profileRate = stats.getTotalApplicants() == 0 ? 0
                : (int) Math.round(100.0 * stats.getCompleteProfiles() / stats.getTotalApplicants());
        model.addAttribute("profileCoverageRate", profileRate);

        long monthlyTotal = monthlyCounts.stream()
                .mapToLong(count -> count == null ? 0L : count.longValue())
                .sum();
        model.addAttribute("monthlyAverage", range == 0 ? 0 : Math.round((double) monthlyTotal / range));

        return "admin/analytics";
    }

    @GetMapping("/admin/audit-log")
    public String auditLog(
            @RequestParam(required = false) String q,
            @RequestParam(required = false) String action,
            @RequestParam(defaultValue = "0") int page,
            Model model) {

        model.addAttribute("auditPage", soft("audit search",
                emptyPage(page),
                () -> auditLogService.search(q, action, page, 25)));
        model.addAttribute("query", q != null ? q : "");
        model.addAttribute("actionFilter", action != null ? action : "");
        model.addAttribute("auditActions", soft("audit actions", List.<String>of(),
                auditLogService::listDistinctActions));
        model.addAttribute("page", page);
        return "admin/audit-log";
    }

    @GetMapping("/admin/search")
    public String globalSearch(
            @RequestParam(required = false) String q,
            Model model) {

        String query = q != null ? q : "";
        model.addAttribute("query", query);
        model.addAttribute("results", soft("admin search",
                new com.scholarzim.dto.AdminSearchResultsDTO(),
                () -> adminSearchService.search(query)));
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
            StoredFileResource storedFile = adminUserService.loadProviderCertificate(
                    userId, authentication.getName());
            String filename = storedFile.displayName() != null
                    ? storedFile.displayName()
                    : "registration-certificate.pdf";
            return ResponseEntity.ok()
                    .header(HttpHeaders.CONTENT_DISPOSITION, "inline; filename=\"" + filename + "\"")
                    .contentType(MediaType.parseMediaType(storedFile.contentType()))
                    .body(storedFile.resource());
        } catch (ResourceNotFoundException notFound) {
            return ResponseEntity.notFound().build();
        } catch (AdminOperationException badRequest) {
            return ResponseEntity.badRequest().build();
        }
    }

    private String handleUserAction(Runnable action, RedirectAttributes redirect, String success) {

        try {
            action.run();
            redirect.addFlashAttribute("successMessage", success);
        } catch (AdminOperationException operationError) {
            redirect.addFlashAttribute("errorMessage", operationError.getMessage());
        } catch (ResourceNotFoundException notFound) {
            redirect.addFlashAttribute("errorMessage", "User not found.");
        }

        return "redirect:/admin/dashboard#user-management";
    }

    private <T> T soft(String label, T fallback, Supplier<T> supplier) {
        return SoftLoad.of(log, "Admin " + label, fallback, supplier);
    }

    private <T> T soft(String label, T fallback, Supplier<T> supplier, AtomicBoolean failed) {
        return SoftLoad.of(log, "Admin " + label, fallback, supplier, failed);
    }

    private static <T> PageResult<T> emptyPage(int page) {
        return new PageResult<>(Collections.emptyList(), Math.max(page, 0), PAGE_SIZE, 0);
    }

    private static List<Long> zeroSeries(int months) {
        return IntStream.range(0, Math.max(months, 0)).mapToObj(i -> 0L).toList();
    }
}
