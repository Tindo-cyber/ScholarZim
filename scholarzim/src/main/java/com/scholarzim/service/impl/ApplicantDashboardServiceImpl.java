package com.scholarzim.service.impl;

import com.scholarzim.dto.ApplicantDashboardDTO;
import com.scholarzim.entity.ApplicantProfile;
import com.scholarzim.entity.Application;
import com.scholarzim.service.ApplicantDashboardService;
import com.scholarzim.service.ApplicantProfileService;
import com.scholarzim.service.ApplicationService;
import com.scholarzim.service.OpportunityService;
import com.scholarzim.service.RecommendationService;
import com.scholarzim.service.SavedScholarshipService;
import org.springframework.stereotype.Service;
import org.springframework.util.StringUtils;

import java.time.LocalDate;
import java.util.List;
import java.util.function.Function;


@Service
public class ApplicantDashboardServiceImpl implements ApplicantDashboardService {

    private final ApplicantProfileService profileService;
    private final ApplicationService applicationService;
    private final SavedScholarshipService savedScholarshipService;
    private final RecommendationService recommendationService;
    private final OpportunityService opportunityService;

    public ApplicantDashboardServiceImpl(
            ApplicantProfileService profileService,
            ApplicationService applicationService,
            SavedScholarshipService savedScholarshipService,
            RecommendationService recommendationService,
            OpportunityService opportunityService) {

        this.profileService = profileService;
        this.applicationService = applicationService;
        this.savedScholarshipService = savedScholarshipService;
        this.recommendationService = recommendationService;
        this.opportunityService = opportunityService;
    }

    @Override
    public ApplicantDashboardDTO getDashboardStats(String email) {

        ApplicantDashboardDTO dto = new ApplicantDashboardDTO();

        boolean hasProfile = profileService.hasProfile(email);
        dto.setHasProfile(hasProfile);
        ApplicantProfile profile = hasProfile ? profileService.getProfileByEmail(email) : null;
        dto.setHasResultsCertificate(profile != null
                && StringUtils.hasText(profile.getResultsCertificatePath()));
        dto.setProfileCompletion(hasProfile ? computeCompletion(profile) : 0);

        List<Application> applications = applicationService.getApplicationsByUser(email);
        dto.setApplicationsSubmitted(applications.size());
        dto.setPendingApplications(countPending(applications));
        dto.setApprovedApplications(countByStatus(applications, "APPROVED"));
        dto.setRejectedApplications(countByStatus(applications, "REJECTED"));
        dto.setSavedCount(savedScholarshipService.listSaved(email).size());

        if (hasProfile) {
            var matches = recommendationService.recommendForApplicant(email);
            dto.setEligibleScholarships(matches.size());
            dto.setUpcomingDeadlinesCount(matches.stream()
                    .map(m -> m.getOpportunity())
                    .filter(o -> o != null && o.getDeadline() != null)
                    .filter(o -> !o.getDeadline().isBefore(LocalDate.now()))
                    .count());
        } else {
            dto.setEligibleScholarships(opportunityService.getActiveOpportunities().size());
            dto.setUpcomingDeadlinesCount(opportunityService.getActiveOpportunities().stream()
                    .filter(o -> o.getDeadline() != null && !o.getDeadline().isBefore(LocalDate.now()))
                    .count());
        }

        return dto;
    }

    private int computeCompletion(ApplicantProfile profile) {
        if (profile == null) {
            return 0;
        }

        List<Function<ApplicantProfile, String>> fields = List.of(
                ApplicantProfile::getEducationLevel,
                ApplicantProfile::getInstitutionName,
                ApplicantProfile::getFieldOfStudy,
                ApplicantProfile::getCountry,
                ApplicantProfile::getProvince,
                ApplicantProfile::getAcademicResults,
                ApplicantProfile::getBiography);

        long filled = fields.stream()
                .map(getter -> getter.apply(profile))
                .filter(StringUtils::hasText)
                .count();

        if (StringUtils.hasText(profile.getResultsCertificatePath())) {
            filled++;
        }

        int total = fields.size() + 1;
        return (int) Math.round((filled * 100.0) / total);
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

    private long countByStatus(List<Application> applications, String status) {
        return applications.stream()
                .filter(a -> status.equals(a.getApplicationStatus()))
                .count();
    }
}
