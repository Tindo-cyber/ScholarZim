package com.scholarzim.service.impl;

import com.scholarzim.dto.PlatformStatsDTO;
import com.scholarzim.repository.ApplicationRepository;
import com.scholarzim.repository.OpportunityRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.PlatformStatsService;
import org.springframework.stereotype.Service;

@Service
public class PlatformStatsServiceImpl implements PlatformStatsService {

    private final OpportunityRepository opportunityRepository;
    private final UserRepository userRepository;
    private final ApplicationRepository applicationRepository;

    public PlatformStatsServiceImpl(
            OpportunityRepository opportunityRepository,
            UserRepository userRepository,
            ApplicationRepository applicationRepository) {

        this.opportunityRepository = opportunityRepository;
        this.userRepository = userRepository;
        this.applicationRepository = applicationRepository;
    }

    @Override
    public PlatformStatsDTO getPublicStats() {

        PlatformStatsDTO stats = new PlatformStatsDTO();
        stats.setTotalScholarships(opportunityRepository.count());
        stats.setActiveScholarships(opportunityRepository.countByStatus("ACTIVE"));
        stats.setTotalApplicants(userRepository.countByRoleRoleName("ROLE_APPLICANT"));
        stats.setTotalProviders(userRepository.countByRoleRoleName("ROLE_PROVIDER"));
        stats.setTotalApplications(applicationRepository.count());
        return stats;
    }
}
