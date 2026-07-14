package com.scholarzim.controller;

import com.scholarzim.dto.ApplicantProfileRequest;
import com.scholarzim.entity.ApplicantProfile;
import com.scholarzim.service.ApplicantProfileService;
import com.scholarzim.util.ProfileCompletionSupport;
import jakarta.validation.Valid;
import lombok.extern.slf4j.Slf4j;
import org.springframework.lang.NonNull;
import org.springframework.security.core.Authentication;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.validation.BindingResult;
import org.springframework.web.bind.annotation.*;
import org.springframework.web.multipart.MultipartFile;
import org.springframework.web.servlet.mvc.support.RedirectAttributes;


@Slf4j
@Controller
public class ApplicantProfileController {

    private final ApplicantProfileService profileService;

    public ApplicantProfileController(ApplicantProfileService profileService) {
        this.profileService = profileService;
    }

    @GetMapping("/applicant/profile")
    public String profilePage(
            @NonNull Authentication authentication,
            @RequestParam(name = "resultsRequired", required = false) Boolean resultsRequired,
            Model model) {

        String email = authentication.getName();
        populateProfileModel(email, model, Boolean.TRUE.equals(resultsRequired));
        return "applicant/profile";
    }

    @PostMapping("/applicant/profile")
    public String saveProfile(
            @Valid @ModelAttribute("profileRequest") ApplicantProfileRequest request,
            BindingResult bindingResult,
            @RequestParam(value = "resultsCertificate", required = false) MultipartFile resultsCertificate,
            @NonNull Authentication authentication,
            Model model,
            RedirectAttributes redirect) {

        if (bindingResult.hasErrors()) {
            populateProfileModel(authentication.getName(), model, false);
            model.addAttribute("profileRequest", request);
            return "applicant/profile";
        }

        try {
            profileService.saveProfile(request, resultsCertificate, authentication.getName());
        } catch (IllegalArgumentException ex) {
            bindingResult.reject("certificateError", ex.getMessage());
            populateProfileModel(authentication.getName(), model, false);
            model.addAttribute("profileRequest", request);
            return "applicant/profile";
        }

        redirect.addFlashAttribute("successMessage", "Academic profile and results certificate saved.");
        return "redirect:/applicant/dashboard";
    }

    @PostMapping("/applicant/profile/documents/{documentType}")
    public String uploadDocument(
            @PathVariable String documentType,
            @RequestParam("file") MultipartFile file,
            @NonNull Authentication authentication,
            RedirectAttributes redirect) {

        try {
            profileService.uploadProfileDocument(documentType, file, authentication.getName());
            redirect.addFlashAttribute(
                    "successMessage",
                    ProfileCompletionSupport.documentLabel(documentType) + " uploaded successfully.");
        } catch (IllegalArgumentException ex) {
            redirect.addFlashAttribute("errorMessage", ex.getMessage());
        }

        return "redirect:/applicant/profile";
    }

    private void populateProfileModel(String email, Model model, boolean resultsRequired) {
        ApplicantProfile profile = null;
        ApplicantProfileRequest profileRequest = new ApplicantProfileRequest();
        boolean hasResultsCertificate = false;
        ProfileCompletionSupport.Snapshot completion =
                ProfileCompletionSupport.build(null, path -> false);

        try {
            profile = profileService.getProfileByEmail(email);
            profileRequest = profileService.toRequest(profile);
            hasResultsCertificate = profileService.hasResultsCertificate(email);
            completion = profileService.getProfileCompletion(email);
        } catch (Exception ex) {
            log.warn("Profile page load failed for {}: {}", email, ex.getMessage());
        }

        model.addAttribute("profileRequest", profileRequest);
        model.addAttribute("existingProfile", profile);
        model.addAttribute("hasResultsCertificate", hasResultsCertificate);
        model.addAttribute("resultsRequired", resultsRequired);
        model.addAttribute("profileCompletion", completion);
    }
}
