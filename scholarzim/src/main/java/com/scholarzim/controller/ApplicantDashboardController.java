package com.scholarzim.controller;

import com.scholarzim.dto.ApplicantDashboardDTO;
import com.scholarzim.dto.ScoredOpportunityDTO;
import com.scholarzim.entity.Application;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.ApplicantDashboardService;
import com.scholarzim.service.ApplicantProfileService;
import com.scholarzim.service.ApplicationService;
import com.scholarzim.service.RecommendationService;
import com.scholarzim.util.GreetingUtil;
import com.scholarzim.util.SoftLoad;
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

        SoftLoad.of(log, "Applicant display name", null, () -> {
            userRepository.findByEmail(email)
                    .ifPresent(u -> model.addAttribute("userFullName", u.getFullName()));
            return null;
        });

        model.addAttribute("greeting", GreetingUtil.timeBasedGreeting());
        model.addAttribute("stats", SoftLoad.of(log, "Applicant dashboard stats",
                new ApplicantDashboardDTO(), () -> dashboardService.getDashboardStats(email)));

        List<ScoredOpportunityDTO> recommendations = SoftLoad.of(log, "Recommendations",
                Collections.emptyList(), () -> recommendationService.recommendForApplicant(email));
        model.addAttribute("recommendations", recommendations.stream().limit(4).toList());
        model.addAttribute("upcomingDeadlines", recommendations.stream()
                .filter(s -> s.getOpportunity() != null && s.getOpportunity().getDeadline() != null)
                .sorted(Comparator.comparing(s -> s.getOpportunity().getDeadline()))
                .limit(8)
                .toList());

        model.addAttribute("recentApplications", SoftLoad.of(log, "Recent applications",
                Collections.emptyList(),
                () -> applicationService.getApplicationsByUser(email).stream()
                        .sorted(Comparator.comparing(Application::getSubmittedAt,
                                Comparator.nullsLast(Comparator.reverseOrder())))
                        .limit(6)
                        .toList()));

        return "applicant/dashboard";
    }

    @GetMapping("/applicant/recommendations")
    public String recommendations(@NonNull Authentication auth, Model model) {

        String email = auth.getName();
        boolean hasProfile = SoftLoad.of(log, "Profile check", false,
                () -> profileService.hasProfile(email));
        List<ScoredOpportunityDTO> opportunities = SoftLoad.of(log, "Recommendations page",
                Collections.emptyList(), () -> recommendationService.recommendForApplicant(email));
        model.addAttribute("hasProfile", hasProfile);
        model.addAttribute("opportunities", opportunities);
        return "applicant/recommendations";
    }
}
