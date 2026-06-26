package com.scholarzim.controller;

import com.scholarzim.exception.AdminOperationException;
import com.scholarzim.exception.ResourceNotFoundException;
import com.scholarzim.service.AdminUserService;
import com.scholarzim.service.AnalyticsService;
import com.scholarzim.service.AuditLogService;
import org.springframework.security.core.Authentication;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.servlet.mvc.support.RedirectAttributes;

import java.time.YearMonth;
import java.time.format.DateTimeFormatter;
import java.util.ArrayList;
import java.util.List;

@Controller
public class AdminController {

    private static final int PAGE_SIZE = 10;

    private final AnalyticsService analyticsService;
    private final AdminUserService adminUserService;
    private final AuditLogService auditLogService;

    public AdminController(
            AnalyticsService analyticsService,
            AdminUserService adminUserService,
            AuditLogService auditLogService) {

        this.analyticsService = analyticsService;
        this.adminUserService = adminUserService;
        this.auditLogService = auditLogService;
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
        List<Long> monthlyCounts = analyticsService.getMonthlyApplicationCounts(months);
        model.addAttribute("monthlyCounts", monthlyCounts);

        DateTimeFormatter fmt = DateTimeFormatter.ofPattern("MMM yyyy");
        List<String> monthLabels = new ArrayList<>();
        YearMonth now = YearMonth.now();
        for (int i = months - 1; i >= 0; i--) {
            monthLabels.add(now.minusMonths(i).format(fmt));
        }
        model.addAttribute("monthLabels", monthLabels);

        model.addAttribute("topProviders", analyticsService.getTopProviders(5));
        model.addAttribute("mostAppliedOpportunities",
                analyticsService.getMostAppliedOpportunities(5));

        return "admin/dashboard";
    }

    @GetMapping("/admin/audit-log")
    public String auditLog(
            @RequestParam(required = false) String q,
            @RequestParam(defaultValue = "0") int page,
            Model model) {

        model.addAttribute("auditPage", auditLogService.search(q, page, 25));
        model.addAttribute("query", q != null ? q : "");
        model.addAttribute("page", page);
        return "admin/audit-log";
    }

    @PostMapping("/admin/users/applicants/{id}/delete")
    public String deleteApplicant(
            @PathVariable Long id,
            Authentication authentication,
            RedirectAttributes redirect) {

        return handleUserAction(() ->
                adminUserService.deleteApplicant(id, authentication.getName()),
                redirect, "Applicant account deleted successfully.");
    }

    @PostMapping("/admin/users/providers/{id}/delete")
    public String deleteProvider(
            @PathVariable Long id,
            Authentication authentication,
            RedirectAttributes redirect) {

        return handleUserAction(() ->
                adminUserService.deleteProvider(id, authentication.getName()),
                redirect, "Provider account deleted successfully.");
    }

    @PostMapping("/admin/users/{id}/suspend")
    public String suspendUser(
            @PathVariable Long id,
            Authentication authentication,
            RedirectAttributes redirect) {

        return handleUserAction(() ->
                adminUserService.suspendUser(id, authentication.getName()),
                redirect, "User suspended.");
    }

    @PostMapping("/admin/users/{id}/reactivate")
    public String reactivateUser(
            @PathVariable Long id,
            Authentication authentication,
            RedirectAttributes redirect) {

        return handleUserAction(() ->
                adminUserService.reactivateUser(id, authentication.getName()),
                redirect, "User reactivated.");
    }

    @PostMapping("/admin/users/providers/{id}/approve")
    public String approveProvider(
            @PathVariable Long id,
            Authentication authentication,
            RedirectAttributes redirect) {

        return handleUserAction(() ->
                adminUserService.approveProvider(id, authentication.getName()),
                redirect, "Provider approved.");
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
