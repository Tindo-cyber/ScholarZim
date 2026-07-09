package com.scholarzim.service.impl;

import com.scholarzim.dto.MatchBreakdownDTO;
import com.scholarzim.dto.ScoredOpportunityDTO;
import com.scholarzim.entity.ApplicantProfile;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.User;
import com.scholarzim.repository.ApplicantProfileRepository;
import com.scholarzim.repository.OpportunityRepository;
import com.scholarzim.service.ApplicantProfileService;
import com.scholarzim.service.RecommendationService;
import org.springframework.stereotype.Service;

import java.time.LocalDate;
import java.time.temporal.ChronoUnit;
import java.util.Comparator;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.stream.Collectors;


@Service
public class RecommendationServiceImpl implements RecommendationService {

    private static final Map<String, Set<String>> RELATED_FIELDS = Map.of(
            "Computer Science", Set.of("Information Technology", "Software Engineering", "Data Science"),
            "Information Technology", Set.of("Computer Science", "Software Engineering"),
            "Medicine", Set.of("Nursing", "Pharmacy", "Public Health"),
            "Accounting", Set.of("Finance", "Economics", "Business Administration"),
            "Engineering", Set.of("Mechanical Engineering", "Civil Engineering", "Electrical Engineering")
    );

    private static final Map<String, Set<String>> RELATED_LEVELS = Map.of(
            "Undergraduate", Set.of("Honours", "Bachelor"),
            "Honours", Set.of("Undergraduate", "Bachelor"),
            "Masters", Set.of("Postgraduate", "PhD"),
            "PhD", Set.of("Postgraduate", "Masters")
    );

    private final ApplicantProfileRepository profileRepository;
    private final OpportunityRepository opportunityRepository;
    private final ApplicantProfileService profileService;

    public RecommendationServiceImpl(
            ApplicantProfileRepository profileRepository,
            OpportunityRepository opportunityRepository,
            ApplicantProfileService profileService) {

        this.profileRepository = profileRepository;
        this.opportunityRepository = opportunityRepository;
        this.profileService = profileService;
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
                .map(opp -> score(profile, opp))
                .filter(scored -> scored.getMatchScore() > 0)
                .sorted(Comparator.comparingInt(ScoredOpportunityDTO::getMatchScore).reversed())
                .collect(Collectors.toList());
    }

    @Override
    public List<User> findMatchingApplicants(Opportunity opportunity) {

        return profileRepository.findAll().stream()
                .filter(profile -> score(profile, opportunity).getMatchScore() > 0)
                .map(ApplicantProfile::getUser)
                .filter(user -> user != null)
                .collect(Collectors.toList());
    }

    private ScoredOpportunityDTO score(ApplicantProfile profile, Opportunity opportunity) {

        MatchBreakdownDTO breakdown = new MatchBreakdownDTO();

        breakdown.setEducationLevelScore(scoreEducationLevel(profile, opportunity));
        breakdown.setFieldScore(scoreField(profile, opportunity));
        breakdown.setLocationScore(scoreLocation(profile, opportunity));
        breakdown.setDeadlineScore(scoreDeadline(opportunity));

        int total = breakdown.totalScore();
        breakdown.setExplanation(String.format(
                "%d%% level · %d%% field · %d%% location · %d%% urgency",
                breakdown.getEducationLevelScore(),
                breakdown.getFieldScore(),
                breakdown.getLocationScore(),
                breakdown.getDeadlineScore()));

        return new ScoredOpportunityDTO(opportunity, total, breakdown);
    }

    private int scoreEducationLevel(ApplicantProfile profile, Opportunity opportunity) {

        String profileLevel = profile.getEducationLevel();
        String oppLevel = opportunity.getEducationLevel();

        if (isBlank(profileLevel) || isBlank(oppLevel)) {
            return 0;
        }

        if (profileLevel.equalsIgnoreCase(oppLevel.trim())) {
            return 30;
        }

        Set<String> related = RELATED_LEVELS.getOrDefault(profileLevel.trim(), Set.of());
        if (related.stream().anyMatch(r -> r.equalsIgnoreCase(oppLevel.trim()))) {
            return 20;
        }

        return 0;
    }

    private int scoreField(ApplicantProfile profile, Opportunity opportunity) {

        String profileField = profile.getFieldOfStudy();
        String oppField = opportunity.getTargetField();

        if (isBlank(profileField) || isBlank(oppField)) {
            return 0;
        }

        if (profileField.equalsIgnoreCase(oppField.trim())) {
            return 35;
        }

        Set<String> related = RELATED_FIELDS.getOrDefault(profileField.trim(), Set.of());
        if (related.stream().anyMatch(r -> r.equalsIgnoreCase(oppField.trim()))) {
            return 25;
        }

        return 0;
    }

    private int scoreLocation(ApplicantProfile profile, Opportunity opportunity) {

        int score = 0;

        if (!isBlank(profile.getCountry()) && !isBlank(opportunity.getTargetCountry())
                && profile.getCountry().equalsIgnoreCase(opportunity.getTargetCountry().trim())) {
            score += 15;
        } else if (!isBlank(profile.getCountry()) && !isBlank(opportunity.getCountry())
                && profile.getCountry().equalsIgnoreCase(opportunity.getCountry().trim())) {
            score += 10;
        }

        if (!isBlank(profile.getProvince()) && "Rural".equalsIgnoreCase(profile.getProvince())) {
            score += 5;
        }

        return Math.min(score, 20);
    }

    private int scoreDeadline(Opportunity opportunity) {

        if (opportunity.getDeadline() == null) {
            return 5;
        }

        long days = ChronoUnit.DAYS.between(LocalDate.now(), opportunity.getDeadline());
        if (days < 0) {
            return 0;
        }
        if (days <= 14) {
            return 15;
        }
        if (days <= 30) {
            return 10;
        }
        return 5;
    }

    private boolean isBlank(String value) {
        return value == null || value.isBlank();
    }
}
