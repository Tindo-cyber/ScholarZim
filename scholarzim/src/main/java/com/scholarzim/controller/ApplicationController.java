package com.scholarzim.controller;

import com.scholarzim.dto.ApplicationSubmitRequest;
import com.scholarzim.exception.DuplicateApplicationException;
import com.scholarzim.service.ApplicationService;
import com.scholarzim.service.ApplicantProfileService;
import com.scholarzim.service.OpportunityService;
import com.scholarzim.util.ApplicationPageSupport;
import jakarta.validation.Valid;
import org.springframework.lang.NonNull;
import org.springframework.security.core.Authentication;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.validation.BindingResult;
import org.springframework.web.bind.annotation.*;
import org.springframework.web.multipart.MultipartFile;
import org.springframework.web.servlet.mvc.support.RedirectAttributes;

import java.util.Collections;
import java.util.List;


@Controller
public class ApplicationController {

    private final ApplicationService applicationService;
    private final OpportunityService opportunityService;
    private final ApplicantProfileService applicantProfileService;

    public ApplicationController(
            ApplicationService applicationService,
            OpportunityService opportunityService,
            ApplicantProfileService applicantProfileService) {

        this.applicationService = applicationService;
        this.opportunityService = opportunityService;
        this.applicantProfileService = applicantProfileService;
    }

    @GetMapping("/my-applications")
    public String myApplications(
            @RequestParam(required = false) String q,
            @RequestParam(required = false, defaultValue = "") String status,
            @RequestParam(defaultValue = "0") int page,
            @NonNull Authentication authentication,
            Model model) {

        model.addAttribute("q", q != null ? q : "");
        model.addAttribute("statusFilter", status != null ? status : "");
        model.addAttribute("currentPage", Math.max(0, page));

        try {
            List<com.scholarzim.entity.Application> all =
                    applicationService.getApplicationsByUser(authentication.getName());
            var result = ApplicationPageSupport.buildPage(all, q, status, page);
            model.addAttribute("applications", result.applications());
            model.addAttribute("filteredTotal", result.filteredTotal());
            model.addAttribute("totalAll", result.totalAll());
            model.addAttribute("approvedCount", result.approvedCount());
            model.addAttribute("pendingCount", result.pendingCount());
            model.addAttribute("rejectedCount", result.rejectedCount());
            model.addAttribute("totalPages", result.totalPages());
            model.addAttribute("currentPage", result.currentPage());
        } catch (RuntimeException ex) {
            model.addAttribute("loadError",
                    ex.getMessage() != null ? ex.getMessage() : "An unexpected error occurred.");
            model.addAttribute("applications", Collections.emptyList());
            model.addAttribute("filteredTotal", 0);
            model.addAttribute("totalAll", 0);
            model.addAttribute("approvedCount", 0);
            model.addAttribute("pendingCount", 0);
            model.addAttribute("rejectedCount", 0);
            model.addAttribute("totalPages", 0);
        }

        return "applications/my-applications";
    }

    @GetMapping("/apply/{opportunityId}")
    public String applyWizard(
            @PathVariable Long opportunityId,
            @NonNull Authentication authentication,
            RedirectAttributes redirect,
            Model model) {

        if (!applicantProfileService.hasResultsCertificate(authentication.getName())) {
            redirect.addFlashAttribute("errorMessage",
                    "Upload your results certificate on your academic profile before applying.");
            return "redirect:/applicant/profile?resultsRequired=1";
        }

        model.addAttribute("opportunity", opportunityService.findById(opportunityId)
                .orElseThrow());
        ApplicationSubmitRequest submitRequest = new ApplicationSubmitRequest();
        submitRequest.setOpportunityId(opportunityId);
        model.addAttribute("submitRequest", submitRequest);
        return "applications/wizard";
    }

    @PostMapping("/apply/{opportunityId}")
    public String submitApplication(
            @PathVariable Long opportunityId,
            @Valid @ModelAttribute("submitRequest") ApplicationSubmitRequest request,
            BindingResult bindingResult,
            @RequestParam(value = "document", required = false) MultipartFile document,
            @NonNull Authentication authentication,
            Model model,
            RedirectAttributes redirect) {

        request.setOpportunityId(opportunityId);

        if (bindingResult.hasErrors()) {
            model.addAttribute("opportunity", opportunityService.findById(opportunityId).orElseThrow());
            return "applications/wizard";
        }

        Long applicationId;
        try {
            applicationId = applicationService.submitApplication(request, document, authentication.getName());
        } catch (DuplicateApplicationException | IllegalArgumentException ex) {
            redirect.addFlashAttribute("errorMessage", ex.getMessage());
            return "redirect:/opportunities";
        }

        return "redirect:/applications/" + applicationId + "/confirmation";
    }

    @GetMapping("/applications/{applicationId}/confirmation")
    public String confirmation(
            @PathVariable Long applicationId,
            @NonNull Authentication authentication,
            Model model) {

        model.addAttribute("application",
                applicationService.getApplicationForUser(applicationId, authentication.getName()));
        return "applications/confirmation";
    }

    @PostMapping("/apply/{opportunityId}/quick")
    public String quickApply(
            @PathVariable Long opportunityId,
            @NonNull Authentication authentication,
            RedirectAttributes redirect) {

        try {
            applicationService.apply(opportunityId, authentication.getName());
            redirect.addFlashAttribute("successMessage", "Application submitted.");
        } catch (Exception ex) {
            redirect.addFlashAttribute("errorMessage", ex.getMessage());
        }
        return "redirect:/opportunities";
    }

    @GetMapping("/provider/applications")
    public String providerApplications(@NonNull Authentication authentication, Model model) {
        model.addAttribute("applications",
                applicationService.getApplicationsForProvider(authentication.getName()));
        return "applications/provider-applications";
    }

    @GetMapping("/provider/applications/{id}")
    public String providerReview(@PathVariable Long id, @NonNull Authentication authentication, Model model) {

        var apps = applicationService.getApplicationsForProvider(authentication.getName());
        var app = apps.stream().filter(a -> a.getApplicationId().equals(id)).findFirst()
                .orElseThrow();
        model.addAttribute("application", app);
        if (app.getUser() != null) {
            model.addAttribute("applicantProfile",
                    applicantProfileService.getProfileByUserId(app.getUser().getUserId()));
        }
        return "applications/provider-review";
    }

    @PostMapping("/provider/applications/{id}/approve")
    public String approve(@PathVariable Long id, @NonNull Authentication authentication, RedirectAttributes redirect) {
        applicationService.updateStatus(id, "APPROVED", authentication.getName());
        redirect.addFlashAttribute("successMessage", "Application approved.");
        return "redirect:/provider/applications";
    }

    @PostMapping("/provider/applications/{id}/reject")
    public String reject(
            @PathVariable Long id,
            @RequestParam(required = false) String rejectionReason,
            @NonNull Authentication authentication,
            RedirectAttributes redirect) {

        applicationService.updateStatus(id, "REJECTED", rejectionReason, authentication.getName());
        redirect.addFlashAttribute("successMessage", "Application rejected.");
        return "redirect:/provider/applications";
    }

    @PostMapping("/provider/applications/{id}/review")
    public String markUnderReview(@PathVariable Long id, @NonNull Authentication authentication, RedirectAttributes redirect) {
        applicationService.updateStatus(id, "UNDER_REVIEW", authentication.getName());
        redirect.addFlashAttribute("successMessage", "Marked as under review.");
        return "redirect:/provider/applications/" + id;
    }

    @PostMapping("/provider/applications/{id}/request-docs")
    public String requestDocs(@PathVariable Long id, @NonNull Authentication authentication, RedirectAttributes redirect) {
        applicationService.updateStatus(id, "DOCUMENTS_REQUESTED", authentication.getName());
        redirect.addFlashAttribute("successMessage", "Document request sent to applicant.");
        return "redirect:/provider/applications/" + id;
    }
}
