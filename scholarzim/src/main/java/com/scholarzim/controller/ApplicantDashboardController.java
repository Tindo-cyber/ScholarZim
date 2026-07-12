package com.scholarzim.controller;

import com.scholarzim.dto.ScoredOpportunityDTO;
import com.scholarzim.entity.Application;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.ApplicantDashboardService;
import com.scholarzim.service.ApplicantProfileService;
import com.scholarzim.service.ApplicationService;
import com.scholarzim.service.RecommendationService;
import com.scholarzim.util.GreetingUtil;
import org.springframework.lang.NonNull;
import org.springframework.security.core.Authentication;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.GetMapping;

import java.util.Comparator;
import java.util.List;


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

        userRepository.findByEmail(auth.getName())
                .ifPresent(u -> model.addAttribute("userFullName", u.getFullName()));

        model.addAttribute("greeting", GreetingUtil.timeBasedGreeting());
        model.addAttribute("stats", dashboardService.getDashboardStats(auth.getName()));

        List<ScoredOpportunityDTO> recommendations =
                recommendationService.recommendForApplicant(auth.getName());
        model.addAttribute("recommendations", recommendations.stream().limit(4).toList());

        model.addAttribute("upcomingDeadlines", recommendations.stream()
                .filter(s -> s.getOpportunity() != null && s.getOpportunity().getDeadline() != null)
                .sorted(Comparator.comparing(s -> s.getOpportunity().getDeadline()))
                .limit(8)
                .toList());

        model.addAttribute("recentApplications",
                applicationService.getApplicationsByUser(auth.getName()).stream()
                        .sorted(Comparator.comparing(Application::getSubmittedAt,
                                Comparator.nullsLast(Comparator.reverseOrder())))
                        .limit(6)
                        .toList());

        return "applicant/dashboard";
    }

    @GetMapping("/applicant/recommendations")
    public String recommendations(@NonNull Authentication auth, Model model) {

        model.addAttribute("hasProfile", profileService.hasProfile(auth.getName()));
        model.addAttribute("opportunities", recommendationService.recommendForApplicant(auth.getName()));
        return "applicant/recommendations";
    }
}
