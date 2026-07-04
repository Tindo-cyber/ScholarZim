package com.scholarzim.service.impl;

import com.scholarzim.dto.ProviderDashboardDTO;
import com.scholarzim.entity.Application;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.User;
import com.scholarzim.exception.ResourceNotFoundException;
import com.scholarzim.repository.ApplicationRepository;
import com.scholarzim.repository.OpportunityRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.ProviderService;
import org.springframework.stereotype.Service;

import java.util.List;


@Service
public class ProviderServiceImpl implements ProviderService {

    private final UserRepository userRepository;
    private final OpportunityRepository opportunityRepository;
    private final ApplicationRepository applicationRepository;

    public ProviderServiceImpl(
            UserRepository userRepository,
            OpportunityRepository opportunityRepository,
            ApplicationRepository applicationRepository) {

        this.userRepository = userRepository;
        this.opportunityRepository = opportunityRepository;
        this.applicationRepository = applicationRepository;
    }

    @Override
    public ProviderDashboardDTO getDashboardStats(String providerEmail) {

        User provider = userRepository.findByEmail(providerEmail)
                .orElseThrow(() -> new ResourceNotFoundException(
                        "Provider account not found."));
        List<Opportunity> opportunities = opportunityRepository.findByProvider(provider);

        ProviderDashboardDTO dto = new ProviderDashboardDTO();
        dto.setTotalOpportunities(opportunities.size());
        dto.setActiveOpportunities(opportunities.stream()
                .filter(o -> "ACTIVE".equalsIgnoreCase(o.getStatus()))
                .count());

        if (opportunities.isEmpty()) {
            return dto;
        }

        List<Application> applications =
                applicationRepository.findByOpportunityIn(opportunities);

        dto.setApplicationsReceived(applications.size());
        dto.setApprovedApplications(countByStatus(applications, "APPROVED"));
        dto.setRejectedApplications(countByStatus(applications, "REJECTED"));
        dto.setPendingApplications(countPending(applications));

        return dto;
    }

    @Override
    public List<Opportunity> getMyOpportunities(String providerEmail) {
        User provider = userRepository.findByEmail(providerEmail)
                .orElseThrow(() -> new ResourceNotFoundException(
                        "Provider account not found."));
        return opportunityRepository.findByProvider(provider);
    }

    @Override
    public List<Application> getRecentApplications(String providerEmail, int limit) {
        List<Opportunity> opportunities = getMyOpportunities(providerEmail);
        if (opportunities.isEmpty()) {
            return List.of();
        }
        return applicationRepository.findByOpportunityIn(opportunities).stream()
                .sorted((a, b) -> {
                    if (a.getSubmittedAt() == null) return 1;
                    if (b.getSubmittedAt() == null) return -1;
                    return b.getSubmittedAt().compareTo(a.getSubmittedAt());
                })
                .limit(limit)
                .toList();
    }

    private long countByStatus(List<Application> applications, String status) {
        return applications.stream()
                .filter(a -> status.equals(a.getApplicationStatus()))
                .count();
    }

    private long countPending(List<Application> applications) {
        return applications.stream()
                .filter(a -> {
                    String s = a.getApplicationStatus();
                    return "PENDING".equals(s) || "SUBMITTED".equals(s)
                            || "UNDER_REVIEW".equals(s) || "DOCUMENTS_REQUESTED".equals(s)
                            || "WAITLISTED".equals(s);
                })
                .count();
    }
}
