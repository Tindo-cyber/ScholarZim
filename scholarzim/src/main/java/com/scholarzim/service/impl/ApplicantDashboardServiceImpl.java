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
import com.scholarzim.util.ProfileCompletionSupport;
import org.springframework.stereotype.Service;
import org.springframework.util.StringUtils;

import java.time.LocalDate;
import java.util.List;


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
        dto.setProfileCompletion(profileService.getProfileCompletion(email).percent());

        List<Application> applications = applicationService.getApplicationsByUser(email);
        dto.setApplicationsSubmitted(applications.size());
        dto.setPendingApplications(countPending(applications));
        dto.setApprovedApplications(countByStatus(applications, "APPROVED"));
        dto.setRejectedApplications(countByStatus(applications, "REJECTED"));
        dto.setSavedCount(savedScholarshipService.countSaved(email));

        if (hasProfile) {
            var matches = recommendationService.recommendForApplicant(email);
            dto.setEligibleScholarships(matches.size());
            dto.setUpcomingDeadlinesCount(matches.stream()
                    .map(m -> m.getOpportunity())
                    .filter(o -> o != null && o.getDeadline() != null)
                    .filter(o -> !o.getDeadline().isBefore(LocalDate.now()))
                    .count());
        } else {
            dto.setEligibleScholarships(opportunityService.countActiveOpportunities());
            dto.setUpcomingDeadlinesCount(opportunityService.countUpcomingDeadlines());
        }

        return dto;
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
