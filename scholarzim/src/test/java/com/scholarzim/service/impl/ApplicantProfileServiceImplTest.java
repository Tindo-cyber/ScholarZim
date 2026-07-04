package com.scholarzim.service.impl;

import com.scholarzim.dto.ApplicantProfileRequest;
import com.scholarzim.entity.ApplicantProfile;
import com.scholarzim.entity.Application;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.Role;
import com.scholarzim.entity.User;
import com.scholarzim.repository.ApplicantProfileRepository;
import com.scholarzim.repository.ApplicationRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.AuditService;
import com.scholarzim.service.FileStorageService;
import com.scholarzim.util.AuditAction;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;
import org.mockito.ArgumentCaptor;
import org.springframework.mock.web.MockMultipartFile;
import org.springframework.security.access.AccessDeniedException;

import java.nio.file.Files;
import java.nio.file.Path;
import java.util.Optional;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertNotNull;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.ArgumentMatchers.eq;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.verify;
import static org.mockito.Mockito.when;

class ApplicantProfileServiceImplTest {

    @TempDir
    Path uploadDir;

    private ApplicantProfileRepository profileRepository;
    private UserRepository userRepository;
    private ApplicationRepository applicationRepository;
    private AuditService auditService;
    private FileStorageService fileStorageService;
    private ApplicantProfileServiceImpl service;

    @BeforeEach
    void setUp() {
        profileRepository = mock(ApplicantProfileRepository.class);
        userRepository = mock(UserRepository.class);
        applicationRepository = mock(ApplicationRepository.class);
        auditService = mock(AuditService.class);
        fileStorageService = new FileStorageService(uploadDir.toString());
        service = new ApplicantProfileServiceImpl(
                profileRepository,
                userRepository,
                applicationRepository,
                fileStorageService,
                auditService);
    }

    @Test
    void firstSaveWithoutPdfThrows() {
        User user = applicant(1L, "student@test.com");
        ApplicantProfileRequest request = profileRequest();

        when(userRepository.findByEmail("student@test.com")).thenReturn(Optional.of(user));
        when(profileRepository.findByUser(user)).thenReturn(Optional.empty());

        assertThrows(IllegalArgumentException.class,
                () -> service.saveProfile(request, null, "student@test.com"));
    }

    @Test
    void firstSaveWithNonPdfRejected() {
        User user = applicant(1L, "student@test.com");
        ApplicantProfileRequest request = profileRequest();
        MockMultipartFile image = new MockMultipartFile(
                "resultsCertificate", "photo.jpg", "image/jpeg", "jpg".getBytes());

        when(userRepository.findByEmail("student@test.com")).thenReturn(Optional.of(user));
        when(profileRepository.findByUser(user)).thenReturn(Optional.empty());

        assertThrows(IllegalArgumentException.class,
                () -> service.saveProfile(request, image, "student@test.com"));
    }

    @Test
    void replaceCertificateDeletesOldPath() throws Exception {
        User user = applicant(2L, "replace@test.com");
        ApplicantProfileRequest request = profileRequest();
        ApplicantProfile existing = new ApplicantProfile();
        existing.setUser(user);

        MockMultipartFile oldPdf = new MockMultipartFile(
                "resultsCertificate", "old.pdf", "application/pdf", "%PDF-1.4 old".getBytes());
        String oldPath = fileStorageService.storePdf(oldPdf, "applicant-results-" + user.getUserId());
        existing.setResultsCertificatePath(oldPath);

        MockMultipartFile pdf = new MockMultipartFile(
                "resultsCertificate", "new.pdf", "application/pdf", "%PDF-1.4 new".getBytes());

        when(userRepository.findByEmail("replace@test.com")).thenReturn(Optional.of(user));
        when(profileRepository.findByUser(any(User.class))).thenReturn(Optional.of(existing));
        when(profileRepository.save(any())).thenAnswer(inv -> inv.getArgument(0));

        service.saveProfile(request, pdf, "replace@test.com");

        assertFalse(Files.exists(fileStorageService.resolve(oldPath)));
        assertFalse(existing.getResultsCertificatePath().equals(oldPath));
        assertTrue(Files.exists(fileStorageService.resolve(existing.getResultsCertificatePath())));
        assertNotNull(existing.getResultsUploadedAt());
    }

    @Test
    void hasResultsCertificateFalseWhenFileMissing() {
        User user = applicant(3L, "missing@test.com");
        ApplicantProfile profile = new ApplicantProfile();
        profile.setUser(user);
        profile.setResultsCertificatePath("gone.pdf");

        when(userRepository.findByEmail("missing@test.com")).thenReturn(Optional.of(user));
        when(profileRepository.findByUser(user)).thenReturn(Optional.of(profile));

        assertFalse(service.hasResultsCertificate("missing@test.com"));
    }

    @Test
    void providerCanLoadResultsForOwnOpportunity() throws Exception {
        User applicant = applicant(4L, "applicant@test.com");
        User provider = provider(5L, "provider@test.com");
        User stranger = provider(6L, "other@test.com");

        ApplicantProfile profile = profileWithCert(applicant, "cert-4.pdf");
        Files.writeString(uploadDir.resolve("cert-4.pdf"), "%PDF-1.4 cert");

        Opportunity opportunity = new Opportunity();
        opportunity.setProvider(provider);

        Application application = new Application();
        application.setApplicationId(10L);
        application.setUser(applicant);
        application.setOpportunity(opportunity);

        when(applicationRepository.findById(10L)).thenReturn(Optional.of(application));
        when(userRepository.findById(4L)).thenReturn(Optional.of(applicant));
        when(profileRepository.findByUser(applicant)).thenReturn(Optional.of(profile));
        when(userRepository.findByEmail("provider@test.com")).thenReturn(Optional.of(provider));

        var file = service.loadResultsCertificateForApplication(10L, "provider@test.com");
        assertEquals("results.pdf", file.displayName());
        assertTrue(file.resource().exists());

        when(userRepository.findByEmail("other@test.com")).thenReturn(Optional.of(stranger));
        assertThrows(AccessDeniedException.class,
                () -> service.loadResultsCertificateForApplication(10L, "other@test.com"));
    }

    @Test
    void loadResultsLogsAuditEvent() throws Exception {
        User applicant = applicant(7L, "audit@test.com");
        ApplicantProfile profile = profileWithCert(applicant, "cert-7.pdf");
        Files.writeString(uploadDir.resolve("cert-7.pdf"), "%PDF-1.4 cert");

        Application application = new Application();
        application.setApplicationId(11L);
        application.setUser(applicant);

        when(applicationRepository.findById(11L)).thenReturn(Optional.of(application));
        when(userRepository.findById(7L)).thenReturn(Optional.of(applicant));
        when(profileRepository.findByUser(applicant)).thenReturn(Optional.of(profile));
        when(userRepository.findByEmail("audit@test.com")).thenReturn(Optional.of(applicant));

        service.loadResultsCertificateForApplication(11L, "audit@test.com");

        ArgumentCaptor<String> actionCaptor = ArgumentCaptor.forClass(String.class);
        verify(auditService).log(
                eq("audit@test.com"),
                actionCaptor.capture(),
                eq("USER"),
                eq(7L),
                any());
        assertEquals(AuditAction.VIEW_APPLICANT_RESULTS, actionCaptor.getValue());
    }

    private static ApplicantProfileRequest profileRequest() {
        ApplicantProfileRequest request = new ApplicantProfileRequest();
        request.setEducationLevel("Undergraduate");
        request.setFieldOfStudy("Engineering");
        request.setCountry("Zimbabwe");
        request.setAcademicResults("Pass");
        return request;
    }

    private static ApplicantProfile profileWithCert(User user, String path) {
        ApplicantProfile profile = new ApplicantProfile();
        profile.setUser(user);
        profile.setResultsCertificatePath(path);
        profile.setResultsCertificateFilename("results.pdf");
        return profile;
    }

    private static User applicant(Long id, String email) {
        User user = new User();
        user.setUserId(id);
        user.setEmail(email);
        Role role = new Role();
        role.setRoleName("ROLE_APPLICANT");
        user.setRole(role);
        return user;
    }

    private static User provider(Long id, String email) {
        User user = new User();
        user.setUserId(id);
        user.setEmail(email);
        Role role = new Role();
        role.setRoleName("ROLE_PROVIDER");
        user.setRole(role);
        return user;
    }
}
