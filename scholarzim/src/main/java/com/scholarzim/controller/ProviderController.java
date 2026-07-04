package com.scholarzim.controller;

import com.scholarzim.entity.Application;
import com.scholarzim.entity.User;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.ProviderService;
import org.springframework.lang.NonNull;
import org.springframework.security.core.Authentication;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.GetMapping;

import java.util.Comparator;
import java.util.List;


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
        User provider = userRepository.findByEmail(email).orElseThrow();
        var stats = providerService.getDashboardStats(email);
        List<Application> recentApplications = providerService.getRecentApplications(email, 8);

        model.addAttribute("providerName", provider.getFullName());
        model.addAttribute("stats", stats);
        model.addAttribute("opportunities", providerService.getMyOpportunities(email));
        model.addAttribute("opportunityCount", stats.getTotalOpportunities());
        model.addAttribute("applicationCount", stats.getApplicationsReceived());
        model.addAttribute("pendingCount", stats.getPendingApplications());
        model.addAttribute("recentApplications", recentApplications);
        model.addAttribute("upcomingDeadlines", providerService.getMyOpportunities(email).stream()
                .filter(o -> o.getDeadline() != null)
                .sorted(Comparator.comparing(o -> o.getDeadline()))
                .limit(5)
                .toList());

        return "provider/dashboard";
    }
}
