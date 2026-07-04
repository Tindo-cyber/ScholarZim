package com.scholarzim.service.impl;

import com.scholarzim.dto.ApplicantDashboardDTO;
import com.scholarzim.entity.ApplicantProfile;
import com.scholarzim.entity.Application;
import com.scholarzim.service.ApplicantDashboardService;
import com.scholarzim.service.ApplicantProfileService;
import com.scholarzim.service.ApplicationService;
import com.scholarzim.service.SavedScholarshipService;
import org.springframework.stereotype.Service;
import org.springframework.util.StringUtils;

import java.util.List;
import java.util.function.Function;


@Service
public class ApplicantDashboardServiceImpl implements ApplicantDashboardService {

    private final ApplicantProfileService profileService;
    private final ApplicationService applicationService;
    private final SavedScholarshipService savedScholarshipService;

    public ApplicantDashboardServiceImpl(
            ApplicantProfileService profileService,
            ApplicationService applicationService,
            SavedScholarshipService savedScholarshipService) {

        this.profileService = profileService;
        this.applicationService = applicationService;
        this.savedScholarshipService = savedScholarshipService;
    }

    @Override
    public ApplicantDashboardDTO getDashboardStats(String email) {

        ApplicantDashboardDTO dto = new ApplicantDashboardDTO();

        boolean hasProfile = profileService.hasProfile(email);
        dto.setHasProfile(hasProfile);
        dto.setProfileCompletion(hasProfile
                ? computeCompletion(profileService.getProfileByEmail(email))
                : 0);

        List<Application> applications = applicationService.getApplicationsByUser(email);
        dto.setApplicationsSubmitted(applications.size());
        dto.setPendingApplications(countPending(applications));
        dto.setApprovedApplications(countByStatus(applications, "APPROVED"));
        dto.setRejectedApplications(countByStatus(applications, "REJECTED"));
        dto.setSavedCount(savedScholarshipService.listSaved(email).size());

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

        return (int) Math.round((filled * 100.0) / fields.size());
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
