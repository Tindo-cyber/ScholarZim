package com.scholarzim.service.impl;

import com.scholarzim.dto.ScoredOpportunityDTO;
import com.scholarzim.entity.ApplicantProfile;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.User;
import com.scholarzim.repository.ApplicantProfileRepository;
import com.scholarzim.repository.OpportunityRepository;
import com.scholarzim.service.ApplicantProfileService;
import com.scholarzim.service.RecommendationService;
import com.scholarzim.service.scholarfit.ScholarFitEngine;
import org.springframework.stereotype.Service;

import java.time.LocalDate;
import java.util.Comparator;
import java.util.List;
import java.util.stream.Collectors;


@Service
public class RecommendationServiceImpl implements RecommendationService {

    private final ApplicantProfileRepository profileRepository;
    private final OpportunityRepository opportunityRepository;
    private final ApplicantProfileService profileService;
    private final ScholarFitEngine scholarFitEngine;

    public RecommendationServiceImpl(
            ApplicantProfileRepository profileRepository,
            OpportunityRepository opportunityRepository,
            ApplicantProfileService profileService,
            ScholarFitEngine scholarFitEngine) {

        this.profileRepository = profileRepository;
        this.opportunityRepository = opportunityRepository;
        this.profileService = profileService;
        this.scholarFitEngine = scholarFitEngine;
    }

    @Override
    public List<ScoredOpportunityDTO> recommendForApplicant(String email) {

        ApplicantProfile profile = profileService.getProfileByEmail(email);
        if (profile == null) {
            return List.of();
        }

        return opportunityRepository.search(
                        LocalDate.now(), null, null, null, null, null, null)
                .stream()
                .map(opp -> scholarFitEngine.evaluate(profile, opp))
                .filter(scored -> scored.getMatchScore() > 0)
                .sorted(Comparator.comparingInt(ScoredOpportunityDTO::getMatchScore).reversed())
                .collect(Collectors.toList());
    }

    @Override
    public List<User> findMatchingApplicants(Opportunity opportunity) {

        return profileRepository.findAllWithUser().stream()
                .filter(profile -> scholarFitEngine.evaluate(profile, opportunity).getMatchScore() > 0)
                .map(ApplicantProfile::getUser)
                .filter(user -> user != null)
                .collect(Collectors.toList());
    }
}
