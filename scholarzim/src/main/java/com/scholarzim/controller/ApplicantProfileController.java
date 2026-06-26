package com.scholarzim.controller;

import com.scholarzim.dto.ApplicantProfileRequest;
import com.scholarzim.service.ApplicantProfileService;
import jakarta.validation.Valid;
import org.springframework.security.core.Authentication;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.validation.BindingResult;
import org.springframework.web.bind.annotation.*;

@Controller
public class ApplicantProfileController {

    private final ApplicantProfileService profileService;

    public ApplicantProfileController(
            ApplicantProfileService profileService) {

        this.profileService = profileService;
    }

    @GetMapping("/applicant/profile")
    public String profilePage(
            Authentication authentication,
            Model model) {

        var profile = profileService.getProfileByEmail(
                authentication.getName());

        model.addAttribute(
                "profileRequest",
                profileService.toRequest(profile));

        return "applicant/profile";
    }

    @PostMapping("/applicant/profile")
    public String saveProfile(
            @Valid @ModelAttribute("profileRequest") ApplicantProfileRequest request,
            BindingResult bindingResult,
            Authentication authentication) {

        if (bindingResult.hasErrors()) {
            return "applicant/profile";
        }

        profileService.saveProfile(
                request,
                authentication.getName());

        return "redirect:/applicant/dashboard";
    }
}
