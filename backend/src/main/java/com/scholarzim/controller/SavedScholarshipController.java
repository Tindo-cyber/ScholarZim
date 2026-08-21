package com.scholarzim.controller;

import com.scholarzim.service.SavedScholarshipService;
import lombok.extern.slf4j.Slf4j;
import org.springframework.lang.NonNull;
import org.springframework.security.core.Authentication;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.servlet.mvc.support.RedirectAttributes;

import java.util.Collections;


@Slf4j
@Controller
public class SavedScholarshipController {

    private final SavedScholarshipService savedScholarshipService;

    public SavedScholarshipController(SavedScholarshipService savedScholarshipService) {
        this.savedScholarshipService = savedScholarshipService;
    }

    @GetMapping("/applicant/saved")
    public String listSaved(@NonNull Authentication auth, Model model) {
        try {
            model.addAttribute("saved", savedScholarshipService.listSaved(auth.getName()));
        } catch (Exception ex) {
            log.warn("Saved scholarships list failed for {}: {}", auth.getName(), ex.getMessage(), ex);
            model.addAttribute("saved", Collections.emptyList());
        }
        return "applicant/saved";
    }

    @PostMapping("/applicant/saved/{id}")
    public String save(
            @PathVariable Long id,
            @NonNull Authentication auth,
            RedirectAttributes redirect) {

        try {
            savedScholarshipService.save(auth.getName(), id);
            redirect.addFlashAttribute("successMessage", "Scholarship saved.");
        } catch (Exception ex) {
            log.warn("Save scholarship failed for {}: {}", auth.getName(), ex.getMessage());
            redirect.addFlashAttribute("errorMessage", "Could not save scholarship. Please try again.");
        }
        return "redirect:/scholarships/" + id;
    }

    @PostMapping("/applicant/saved/{id}/remove")
    public String remove(
            @PathVariable Long id,
            @NonNull Authentication auth,
            RedirectAttributes redirect) {

        try {
            savedScholarshipService.remove(auth.getName(), id);
            redirect.addFlashAttribute("successMessage", "Removed from saved list.");
        } catch (Exception ex) {
            log.warn("Remove saved scholarship failed for {}: {}", auth.getName(), ex.getMessage());
            redirect.addFlashAttribute("errorMessage", "Could not remove saved scholarship.");
        }
        return "redirect:/applicant/saved";
    }
}
