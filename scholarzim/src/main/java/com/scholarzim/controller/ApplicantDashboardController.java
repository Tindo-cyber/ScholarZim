package com.scholarzim.controller;

import com.scholarzim.dto.ScoredOpportunityDTO;
import com.scholarzim.entity.Application;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.ApplicantDashboardService;
import com.scholarzim.service.ApplicantProfileService;
import com.scholarzim.service.ApplicationService;
import com.scholarzim.service.RecommendationService;
import com.scholarzim.util.GreetingUtil;
import lombok.extern.slf4j.Slf4j;
import org.springframework.lang.NonNull;
import org.springframework.security.core.Authentication;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.GetMapping;

import java.util.Collections;
import java.util.Comparator;
import java.util.List;


@Slf4j
@Controller
public class ApplicantDashboardController {

    private final ApplicantDashboardService dashboardService;
    private final RecommendationService recommendationService;
    private final ApplicationService applicationService;
    private final ApplicantProfileService profileService;
    private final UserRepository userRepository;

    public ApplicantDashboardController(
            ApplicantDashboardService dashboardService,
            RecommendationService recommendationService,
            ApplicationService applicationService,
            ApplicantProfileService profileService,
            UserRepository userRepository) {

        this.dashboardService = dashboardService;
        this.recommendationService = recommendationService;
        this.applicationService = applicationService;
        this.profileService = profileService;
        this.userRepository = userRepository;
    }

    @GetMapping("/applicant/dashboard")
    public String dashboard(@NonNull Authentication auth, Model model) {

        String email = auth.getName();
        try {
            userRepository.findByEmail(email)
                    .ifPresent(u -> model.addAttribute("userFullName", u.getFullName()));
        } catch (Exception ex) {
            log.warn("Could not load user display name for {}: {}", email, ex.getMessage());
        }

        model.addAttribute("greeting", GreetingUtil.timeBasedGreeting());
        model.addAttribute("stats", dashboardService.getDashboardStats(email));

        List<ScoredOpportunityDTO> recommendations = Collections.emptyList();
        try {
            recommendations = recommendationService.recommendForApplicant(email);
        } catch (Exception ex) {
            log.warn("Recommendations failed for {}: {}", email, ex.getMessage());
        }
        model.addAttribute("recommendations", recommendations.stream().limit(4).toList());
        model.addAttribute("upcomingDeadlines", recommendations.stream()
                .filter(s -> s.getOpportunity() != null && s.getOpportunity().getDeadline() != null)
                .sorted(Comparator.comparing(s -> s.getOpportunity().getDeadline()))
                .limit(8)
                .toList());

        try {
            model.addAttribute("recentApplications",
                    applicationService.getApplicationsByUser(email).stream()
                            .sorted(Comparator.comparing(Application::getSubmittedAt,
                                    Comparator.nullsLast(Comparator.reverseOrder())))
                            .limit(6)
                            .toList());
        } catch (Exception ex) {
            log.warn("Recent applications failed for {}: {}", email, ex.getMessage());
            model.addAttribute("recentApplications", Collections.emptyList());
        }

        return "applicant/dashboard";
    }

    @GetMapping("/applicant/recommendations")
    public String recommendations(@NonNull Authentication auth, Model model) {

        String email = auth.getName();
        boolean hasProfile = false;
        List<ScoredOpportunityDTO> opportunities = Collections.emptyList();
        try {
            hasProfile = profileService.hasProfile(email);
        } catch (Exception ex) {
            log.warn("Profile check failed for {}: {}", email, ex.getMessage());
        }
        try {
            opportunities = recommendationService.recommendForApplicant(email);
        } catch (Exception ex) {
            log.warn("Recommendations page failed for {}: {}", email, ex.getMessage());
        }
        model.addAttribute("hasProfile", hasProfile);
        model.addAttribute("opportunities", opportunities);
        return "applicant/recommendations";
    }
}
