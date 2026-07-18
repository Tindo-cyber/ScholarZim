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
import java.util.concurrent.atomic.AtomicBoolean;


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
        AtomicBoolean loadFailed = new AtomicBoolean(false);

        SoftLoad.of(log, "Applicant display name", null, () -> {
            userRepository.findByEmail(email)
                    .ifPresent(u -> model.addAttribute("userFullName", u.getFullName()));
            return null;
        }, loadFailed);

        model.addAttribute("greeting", GreetingUtil.timeBasedGreeting());
        model.addAttribute("stats", SoftLoad.of(log, "Applicant dashboard stats",
                new ApplicantDashboardDTO(), () -> dashboardService.getDashboardStats(email), loadFailed));

        List<ScoredOpportunityDTO> recommendations = SoftLoad.of(log, "Recommendations",
                Collections.emptyList(), () -> recommendationService.recommendForApplicant(email), loadFailed);
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
                        .toList(),
                loadFailed));
        model.addAttribute("loadFailed", loadFailed.get());
        // #region agent log
        var statsAttr = model.getAttribute("stats");
        int recCount = recommendations != null ? recommendations.size() : -1;
        Object recent = model.getAttribute("recentApplications");
        int appCount = recent instanceof java.util.Collection<?> c ? c.size() : -1;
        long savedCount = 0L;
        long submittedApps = 0L;
        if (statsAttr instanceof ApplicantDashboardDTO dto) {
            savedCount = dto.getSavedCount();
            submittedApps = dto.getApplicationsSubmitted();
        }
        String name = email != null ? email : "";
        boolean demoApplicant = "tanaka.moyo@student.co.zw".equalsIgnoreCase(name)
                || "rudo.chikomo@student.co.zw".equalsIgnoreCase(name)
                || "simba.ndlovu@student.co.zw".equalsIgnoreCase(name);
        com.scholarzim.debug.AgentDebugLog.log("B", "ApplicantDashboardController.dashboard", "dashboard_loaded",
                java.util.Map.of(
                        "loadFailed", loadFailed.get(),
                        "recommendationCount", recCount,
                        "recentApplicationCount", appCount,
                        "statsSavedCount", savedCount,
                        "statsApplicationsSubmitted", submittedApps,
                        "isDemoApplicant", demoApplicant));
        com.scholarzim.debug.AgentDebugLog.log("E", "ApplicantDashboardController.dashboard", "account_type",
                java.util.Map.of("isDemoApplicant", demoApplicant, "loadFailed", loadFailed.get()));
        // #endregion

        return "applicant/dashboard";
    }

    @GetMapping("/applicant/recommendations")
    public String recommendations(@NonNull Authentication auth, Model model) {

        String email = auth.getName();
        AtomicBoolean loadFailed = new AtomicBoolean(false);
        boolean hasProfile = SoftLoad.of(log, "Profile check", false,
                () -> profileService.hasProfile(email), loadFailed);
        List<ScoredOpportunityDTO> opportunities = SoftLoad.of(log, "Recommendations page",
                Collections.emptyList(), () -> recommendationService.recommendForApplicant(email), loadFailed);
        model.addAttribute("hasProfile", hasProfile);
        model.addAttribute("opportunities", opportunities);
        model.addAttribute("loadFailed", loadFailed.get());
        return "applicant/recommendations";
    }
}
