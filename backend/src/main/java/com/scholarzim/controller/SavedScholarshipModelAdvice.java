package com.scholarzim.controller;

import com.scholarzim.service.SavedScholarshipService;
import org.springframework.lang.Nullable;
import org.springframework.security.authentication.AnonymousAuthenticationToken;
import org.springframework.security.core.Authentication;
import org.springframework.security.core.GrantedAuthority;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.ControllerAdvice;
import org.springframework.web.bind.annotation.ModelAttribute;

import java.util.Collections;
import java.util.Set;


@ControllerAdvice
public class SavedScholarshipModelAdvice {

    private final SavedScholarshipService savedScholarshipService;

    public SavedScholarshipModelAdvice(SavedScholarshipService savedScholarshipService) {
        this.savedScholarshipService = savedScholarshipService;
    }

    @ModelAttribute
    public void populateSavedOpportunityIds(Model model, @Nullable Authentication authentication) {

        if (authentication == null
                || !authentication.isAuthenticated()
                || authentication instanceof AnonymousAuthenticationToken) {
            model.addAttribute("savedOpportunityIds", Collections.emptySet());
            return;
        }

        boolean isApplicant = authentication.getAuthorities().stream()
                .map(GrantedAuthority::getAuthority)
                .anyMatch("ROLE_APPLICANT"::equals);

        if (!isApplicant) {
            model.addAttribute("savedOpportunityIds", Collections.emptySet());
            return;
        }

        try {
            Set<Long> savedIds = savedScholarshipService.listSavedOpportunityIds(authentication.getName());
            model.addAttribute("savedOpportunityIds", savedIds);
        } catch (Exception ex) {
            model.addAttribute("savedOpportunityIds", Collections.emptySet());
        }
    }
}
