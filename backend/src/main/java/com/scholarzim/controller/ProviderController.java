package com.scholarzim.controller;

import com.scholarzim.dto.ProviderDashboardDTO;
import com.scholarzim.entity.Application;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.User;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.ProviderService;
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
public class ProviderController {

    private final UserRepository userRepository;
    private final ProviderService providerService;

    public ProviderController(
            UserRepository userRepository,
            ProviderService providerService) {

        this.userRepository = userRepository;
        this.providerService = providerService;
    }

    @GetMapping("/provider/dashboard")
    public String dashboard(@NonNull Authentication auth, Model model) {

        String email = auth.getName();

        String providerName = SoftLoad.of(log, "Provider name", "Provider",
                () -> userRepository.findByEmail(email).map(User::getFullName).orElse(email));

        ProviderDashboardDTO stats = SoftLoad.of(log, "Provider dashboard stats",
                new ProviderDashboardDTO(), () -> providerService.getDashboardStats(email));

        List<Opportunity> opportunities = SoftLoad.of(log, "Provider opportunities",
                Collections.emptyList(), () -> providerService.getMyOpportunities(email));

        List<Application> recentApplications = SoftLoad.of(log, "Provider recent applications",
                Collections.emptyList(), () -> providerService.getRecentApplications(email, 8));

        model.addAttribute("providerName", providerName);
        model.addAttribute("stats", stats);
        model.addAttribute("opportunities", opportunities);
        model.addAttribute("opportunityCount", stats.getTotalOpportunities());
        model.addAttribute("applicationCount", stats.getApplicationsReceived());
        model.addAttribute("pendingCount", stats.getPendingApplications());
        model.addAttribute("recentApplications", recentApplications);
        model.addAttribute("upcomingDeadlines", opportunities.stream()
                .filter(o -> o.getDeadline() != null)
                .sorted(Comparator.comparing(Opportunity::getDeadline))
                .limit(5)
                .toList());

        return "provider/dashboard";
    }
}
