package com.scholarzim.controller;

import com.scholarzim.entity.Application;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.User;
import com.scholarzim.repository.ApplicationRepository;
import com.scholarzim.repository.OpportunityRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.util.ApplicationStatus;
import org.springframework.security.core.Authentication;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.GetMapping;

import java.util.HashMap;
import java.util.List;
import java.util.Map;

@Controller
public class ProviderController {

    private final UserRepository userRepository;
    private final OpportunityRepository opportunityRepository;
    private final ApplicationRepository applicationRepository;

    public ProviderController(
            UserRepository userRepository,
            OpportunityRepository opportunityRepository,
            ApplicationRepository applicationRepository) {

        this.userRepository = userRepository;
        this.opportunityRepository = opportunityRepository;
        this.applicationRepository = applicationRepository;
    }

    @GetMapping("/provider/dashboard")
    public String dashboard(Authentication auth, Model model) {

        User provider = userRepository.findByEmail(auth.getName()).orElseThrow();
        List<Opportunity> opportunities = opportunityRepository.findByProvider(provider);
        List<Application> applications = opportunities.isEmpty()
                ? List.of()
                : applicationRepository.findByOpportunityIn(opportunities);

        model.addAttribute("providerName", provider.getFullName());
        model.addAttribute("opportunityCount", opportunities.size());
        model.addAttribute("applicationCount", applications.size());
        model.addAttribute("pendingCount", applications.stream().filter(a ->
                ApplicationStatus.SUBMITTED.equals(a.getApplicationStatus())
                        || ApplicationStatus.UNDER_REVIEW.equals(a.getApplicationStatus())
                        || ApplicationStatus.PENDING.equals(a.getApplicationStatus())).count());

        Map<String, Long> statusCounts = new HashMap<>();
        for (Application app : applications) {
            String status = app.getApplicationStatus() != null ? app.getApplicationStatus() : "UNKNOWN";
            statusCounts.merge(status, 1L, (left, right) -> left + right);
        }
        model.addAttribute("statusCounts", statusCounts);
        model.addAttribute("recentApplications", applications.stream()
                .sorted((a, b) -> {
                    if (a.getSubmittedAt() == null) return 1;
                    if (b.getSubmittedAt() == null) return -1;
                    return b.getSubmittedAt().compareTo(a.getSubmittedAt());
                })
                .limit(8)
                .toList());

        return "provider/dashboard";
    }
}
