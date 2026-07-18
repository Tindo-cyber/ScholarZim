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
            var saved = savedScholarshipService.listSaved(auth.getName());
            model.addAttribute("saved", saved);
            model.addAttribute("loadFailed", false);
            // #region agent log
            String name = auth.getName() != null ? auth.getName() : "";
            boolean demoApplicant = "tanaka.moyo@student.co.zw".equalsIgnoreCase(name)
                    || "rudo.chikomo@student.co.zw".equalsIgnoreCase(name)
                    || "simba.ndlovu@student.co.zw".equalsIgnoreCase(name);
            com.scholarzim.debug.AgentDebugLog.log("C", "SavedScholarshipController.listSaved", "saved_ok",
                    java.util.Map.of(
                            "count", saved.size(),
                            "empty", saved.isEmpty(),
                            "isDemoApplicant", demoApplicant));
            // #endregion
            // #region agent log
            if (saved.isEmpty()) {
                com.scholarzim.debug.AgentDebugLog.log("D", "SavedScholarshipController.listSaved",
                        "saved_empty_shows_empty_state",
                        java.util.Map.of("count", 0, "isDemoApplicant", demoApplicant));
            }
            // #endregion
        } catch (Exception ex) {
            log.warn("Saved scholarships list failed for {}: {}", auth.getName(), ex.getMessage());
            model.addAttribute("saved", Collections.emptyList());
            model.addAttribute("loadFailed", true);
            // #region agent log
            com.scholarzim.debug.AgentDebugLog.log("C", "SavedScholarshipController.listSaved", "saved_failed",
                    java.util.Map.of(
                            "exClass", ex.getClass().getName(),
                            "exMessage", String.valueOf(ex.getMessage())));
            // #endregion
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
