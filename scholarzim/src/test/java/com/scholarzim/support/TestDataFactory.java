package com.scholarzim.support;

import com.scholarzim.entity.ApplicantProfile;
import com.scholarzim.entity.Application;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.ProviderProfile;
import com.scholarzim.entity.Role;
import com.scholarzim.entity.User;
import com.scholarzim.repository.ApplicationRepository;
import com.scholarzim.repository.ApplicantProfileRepository;
import com.scholarzim.repository.OpportunityRepository;
import com.scholarzim.repository.ProviderProfileRepository;
import com.scholarzim.repository.RoleRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.FileStorageService;
import com.scholarzim.util.ProviderOrgType;
import org.springframework.mock.web.MockMultipartFile;

import java.io.IOException;
import java.io.UncheckedIOException;
import java.time.LocalDate;
import java.time.LocalDateTime;
import java.util.UUID;

public class TestDataFactory {

    private final UserRepository userRepository;
    private final RoleRepository roleRepository;
    private final OpportunityRepository opportunityRepository;
    private final ApplicationRepository applicationRepository;
    private final ApplicantProfileRepository applicantProfileRepository;
    private final ProviderProfileRepository providerProfileRepository;
    private final FileStorageService fileStorageService;

    public TestDataFactory(
            UserRepository userRepository,
            RoleRepository roleRepository,
            OpportunityRepository opportunityRepository,
            ApplicationRepository applicationRepository) {

        this(userRepository, roleRepository, opportunityRepository, applicationRepository, null, null, null);
    }

    public TestDataFactory(
            UserRepository userRepository,
            RoleRepository roleRepository,
            OpportunityRepository opportunityRepository,
            ApplicationRepository applicationRepository,
            ApplicantProfileRepository applicantProfileRepository,
            ProviderProfileRepository providerProfileRepository,
            FileStorageService fileStorageService) {

        this.userRepository = userRepository;
        this.roleRepository = roleRepository;
        this.opportunityRepository = opportunityRepository;
        this.applicationRepository = applicationRepository;
        this.applicantProfileRepository = applicantProfileRepository;
        this.providerProfileRepository = providerProfileRepository;
        this.fileStorageService = fileStorageService;
    }

    public Role applicantRole() {
        return roleRepository.findByRoleName("ROLE_APPLICANT").orElseThrow();
    }

    public Role providerRole() {
        return roleRepository.findByRoleName("ROLE_PROVIDER").orElseThrow();
    }

    public Role adminRole() {
        return roleRepository.findByRoleName("ROLE_ADMIN").orElseThrow();
    }

    public User saveApplicant(String email) {
        User user = new User();
        user.setFullName("Test Applicant");
        user.setEmail(email);
        user.setPasswordHash("hash");
        user.setAccountStatus("ACTIVE");
        user.setRole(applicantRole());
        return userRepository.save(user);
    }

    public User saveProvider(String email) {
        User user = new User();
        user.setFullName("Test Provider");
        user.setEmail(email);
        user.setPasswordHash("hash");
        user.setAccountStatus("ACTIVE");
        user.setRole(providerRole());
        return userRepository.save(user);
    }

    public User savePendingProvider(String email) {
        User user = new User();
        user.setFullName("Pending Provider");
        user.setEmail(email);
        user.setPasswordHash("hash");
        user.setAccountStatus("PENDING_APPROVAL");
        user.setRole(providerRole());
        return userRepository.save(user);
    }

    public User saveAdmin(String email) {
        User user = new User();
        user.setFullName("Test Admin");
        user.setEmail(email);
        user.setPasswordHash("hash");
        user.setAccountStatus("ACTIVE");
        user.setRole(adminRole());
        return userRepository.save(user);
    }

    public User savePendingProviderWithProfile(String email) {
        requireProfileSupport();
        User user = savePendingProvider(email);
        String certPath = storeProviderCertificate(user.getUserId());
        ProviderProfile profile = new ProviderProfile();
        profile.setUser(user);
        profile.setOrganisationType(ProviderOrgType.NGO);
        profile.setRegistrationNumber("NGO-" + UUID.randomUUID());
        profile.setCertificatePath(certPath);
        profile.setCertificateFilename("registration.pdf");
        profile.setSubmittedAt(LocalDateTime.now());
        providerProfileRepository.save(profile);
        return user;
    }

    public ApplicantProfile saveApplicantWithResultsCertificate(String email) {
        requireProfileSupport();
        User user = saveApplicant(email);
        String certPath = storeApplicantResultsCertificate(user.getUserId());
        ApplicantProfile profile = new ApplicantProfile();
        profile.setUser(user);
        profile.setEducationLevel("Undergraduate");
        profile.setInstitutionName("University of Zimbabwe");
        profile.setFieldOfStudy("Computer Science");
        profile.setCountry("Zimbabwe");
        profile.setProvince("Harare");
        profile.setAcademicResults("First Class Honours");
        profile.setResultsCertificatePath(certPath);
        profile.setResultsCertificateFilename("results.pdf");
        profile.setResultsUploadedAt(LocalDateTime.now());
        return applicantProfileRepository.save(profile);
    }

    public Opportunity saveOpportunity(User provider) {
        Opportunity opportunity = new Opportunity();
        opportunity.setProvider(provider);
        opportunity.setTitle("Test Scholarship");
        opportunity.setDescription("Demo opportunity");
        opportunity.setProviderName("Test Org");
        opportunity.setEducationLevel("Undergraduate");
        opportunity.setFundingType("Full");
        opportunity.setCountry("Zimbabwe");
        opportunity.setDeadline(LocalDate.now().plusMonths(3));
        opportunity.setStatus("ACTIVE");
        opportunity.setCreatedAt(LocalDateTime.now());
        return opportunityRepository.save(opportunity);
    }

    public Application saveApplication(User applicant, Opportunity opportunity) {
        Application application = new Application();
        application.setUser(applicant);
        application.setOpportunity(opportunity);
        application.setApplicationStatus("PENDING");
        application.setSubmittedAt(LocalDateTime.now());
        return applicationRepository.save(application);
    }

    public static MockMultipartFile resultsPdf() {
        return new MockMultipartFile(
                "resultsCertificate",
                "results.pdf",
                "application/pdf",
                "%PDF-1.4 ScholarZim test results".getBytes());
    }

    public static MockMultipartFile providerCertificatePdf() {
        return new MockMultipartFile(
                "certificate",
                "registration.pdf",
                "application/pdf",
                "%PDF-1.4 ScholarZim test provider cert".getBytes());
    }

    private String storeProviderCertificate(Long userId) {
        try {
            return fileStorageService.storePdf(providerCertificatePdf(), "provider-verification-" + userId);
        } catch (IOException ex) {
            throw new UncheckedIOException(ex);
        }
    }

    private String storeApplicantResultsCertificate(Long userId) {
        try {
            return fileStorageService.storePdf(resultsPdf(), "applicant-results-" + userId);
        } catch (IOException ex) {
            throw new UncheckedIOException(ex);
        }
    }

    private void requireProfileSupport() {
        if (applicantProfileRepository == null
                || providerProfileRepository == null
                || fileStorageService == null) {
            throw new IllegalStateException(
                    "Profile seeding requires ApplicantProfileRepository, ProviderProfileRepository, and FileStorageService");
        }
    }
}
