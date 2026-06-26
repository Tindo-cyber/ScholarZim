package com.scholarzim.controller;

import com.scholarzim.service.SavedScholarshipService;
import org.springframework.security.core.Authentication;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.servlet.mvc.support.RedirectAttributes;

@Controller
public class SavedScholarshipController {

    private final SavedScholarshipService savedScholarshipService;

    public SavedScholarshipController(SavedScholarshipService savedScholarshipService) {
        this.savedScholarshipService = savedScholarshipService;
    }

    @GetMapping("/applicant/saved")
    public String listSaved(Authentication auth, Model model) {
        model.addAttribute("saved", savedScholarshipService.listSaved(auth.getName()));
        return "applicant/saved";
    }

    @PostMapping("/applicant/saved/{id}")
    public String save(
            @PathVariable Long id,
            Authentication auth,
            RedirectAttributes redirect) {

        savedScholarshipService.save(auth.getName(), id);
        redirect.addFlashAttribute("successMessage", "Scholarship saved.");
        return "redirect:/scholarships/" + id;
    }

    @PostMapping("/applicant/saved/{id}/remove")
    public String remove(
            @PathVariable Long id,
            Authentication auth,
            RedirectAttributes redirect) {

        savedScholarshipService.remove(auth.getName(), id);
        redirect.addFlashAttribute("successMessage", "Removed from saved list.");
        return "redirect:/applicant/saved";
    }
}
