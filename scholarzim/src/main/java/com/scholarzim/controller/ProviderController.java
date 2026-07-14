package com.scholarzim.controller;

import com.scholarzim.dto.ProviderDashboardDTO;
import com.scholarzim.entity.Application;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.User;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.ProviderService;
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
        String providerName = "Provider";
        try {
            providerName = userRepository.findByEmail(email)
                    .map(User::getFullName)
                    .orElse(email);
        } catch (Exception ex) {
            log.warn("Could not load provider name for {}: {}", email, ex.getMessage());
        }

        ProviderDashboardDTO stats = new ProviderDashboardDTO();
        try {
            stats = providerService.getDashboardStats(email);
        } catch (Exception ex) {
            log.warn("Provider dashboard stats failed for {}: {}", email, ex.getMessage());
        }

        List<Opportunity> opportunities = Collections.emptyList();
        try {
            opportunities = providerService.getMyOpportunities(email);
        } catch (Exception ex) {
            log.warn("Provider opportunities failed for {}: {}", email, ex.getMessage());
        }

        List<Application> recentApplications = Collections.emptyList();
        try {
            recentApplications = providerService.getRecentApplications(email, 8);
        } catch (Exception ex) {
            log.warn("Provider recent applications failed for {}: {}", email, ex.getMessage());
        }

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
