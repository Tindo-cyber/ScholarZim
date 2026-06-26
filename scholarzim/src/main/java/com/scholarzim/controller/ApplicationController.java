package com.scholarzim.controller;

import com.scholarzim.dto.ApplicationSubmitRequest;
import com.scholarzim.exception.DuplicateApplicationException;
import com.scholarzim.service.ApplicationService;
import com.scholarzim.service.OpportunityService;
import jakarta.validation.Valid;
import org.springframework.security.core.Authentication;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.validation.BindingResult;
import org.springframework.web.bind.annotation.*;
import org.springframework.web.multipart.MultipartFile;
import org.springframework.web.servlet.mvc.support.RedirectAttributes;

@Controller
public class ApplicationController {

    private final ApplicationService applicationService;
    private final OpportunityService opportunityService;

    public ApplicationController(
            ApplicationService applicationService,
            OpportunityService opportunityService) {

        this.applicationService = applicationService;
        this.opportunityService = opportunityService;
    }

    @GetMapping("/my-applications")
    public String myApplications(Authentication authentication, Model model) {
        model.addAttribute("applications",
                applicationService.getApplicationsByUser(authentication.getName()));
        return "applications/my-applications";
    }

    @GetMapping("/apply/{opportunityId}")
    public String applyWizard(@PathVariable Long opportunityId, Model model) {

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
            Authentication authentication,
            Model model,
            RedirectAttributes redirect) {

        request.setOpportunityId(opportunityId);

        if (bindingResult.hasErrors()) {
            model.addAttribute("opportunity", opportunityService.findById(opportunityId).orElseThrow());
            return "applications/wizard";
        }

        try {
            applicationService.submitApplication(request, document, authentication.getName());
        } catch (DuplicateApplicationException | IllegalArgumentException ex) {
            redirect.addFlashAttribute("errorMessage", ex.getMessage());
            return "redirect:/opportunities";
        }

        redirect.addFlashAttribute("successMessage", "Your application was submitted successfully.");
        return "redirect:/my-applications";
    }

    @PostMapping("/apply/{opportunityId}/quick")
    public String quickApply(
            @PathVariable Long opportunityId,
            Authentication authentication,
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
    public String providerApplications(Authentication authentication, Model model) {
        model.addAttribute("applications",
                applicationService.getApplicationsForProvider(authentication.getName()));
        return "applications/provider-applications";
    }

    @GetMapping("/provider/applications/{id}")
    public String providerReview(@PathVariable Long id, Authentication authentication, Model model) {

        var apps = applicationService.getApplicationsForProvider(authentication.getName());
        var app = apps.stream().filter(a -> a.getApplicationId().equals(id)).findFirst()
                .orElseThrow();
        model.addAttribute("application", app);
        return "applications/provider-review";
    }

    @PostMapping("/provider/applications/{id}/approve")
    public String approve(@PathVariable Long id, Authentication authentication, RedirectAttributes redirect) {
        applicationService.updateStatus(id, "APPROVED", authentication.getName());
        redirect.addFlashAttribute("successMessage", "Application approved.");
        return "redirect:/provider/applications";
    }

    @PostMapping("/provider/applications/{id}/reject")
    public String reject(
            @PathVariable Long id,
            @RequestParam(required = false) String rejectionReason,
            Authentication authentication,
            RedirectAttributes redirect) {

        applicationService.updateStatus(id, "REJECTED", rejectionReason, authentication.getName());
        redirect.addFlashAttribute("successMessage", "Application rejected.");
        return "redirect:/provider/applications";
    }

    @PostMapping("/provider/applications/{id}/review")
    public String markUnderReview(@PathVariable Long id, Authentication authentication, RedirectAttributes redirect) {
        applicationService.updateStatus(id, "UNDER_REVIEW", authentication.getName());
        redirect.addFlashAttribute("successMessage", "Marked as under review.");
        return "redirect:/provider/applications/" + id;
    }

    @PostMapping("/provider/applications/{id}/request-docs")
    public String requestDocs(@PathVariable Long id, Authentication authentication, RedirectAttributes redirect) {
        applicationService.updateStatus(id, "DOCUMENTS_REQUESTED", authentication.getName());
        redirect.addFlashAttribute("successMessage", "Document request sent to applicant.");
        return "redirect:/provider/applications/" + id;
    }
}
