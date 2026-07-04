package com.scholarzim.service.impl;

import com.scholarzim.entity.Application;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.Role;
import com.scholarzim.entity.User;
import com.scholarzim.exception.ResourceNotFoundException;
import com.scholarzim.repository.ApplicationRepository;
import com.scholarzim.repository.OpportunityRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.ApplicantProfileService;
import com.scholarzim.service.AuditService;
import com.scholarzim.service.FileStorageService;
import com.scholarzim.service.NotificationService;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;
import org.springframework.security.access.AccessDeniedException;

import java.nio.file.Files;
import java.nio.file.Path;
import java.util.Optional;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.when;

class ApplicationServiceImplDocumentTest {

    @TempDir
    Path uploadDir;

    private ApplicationRepository applicationRepository;
    private UserRepository userRepository;
    private ApplicationServiceImpl service;
    private FileStorageService fileStorageService;

    @BeforeEach
    void setUp() {
        applicationRepository = mock(ApplicationRepository.class);
        userRepository = mock(UserRepository.class);
        fileStorageService = new FileStorageService(uploadDir.toString());
        service = new ApplicationServiceImpl(
                applicationRepository,
                userRepository,
                mock(OpportunityRepository.class),
                mock(NotificationService.class),
                mock(AuditService.class),
                fileStorageService,
                mock(ApplicantProfileService.class));
    }

    @Test
    void applicantCanDownloadOwnDocument() throws Exception {
        var applicant = user(1L, "applicant@test.com", "APPLICANT");
        var application = applicationWithDocument(10L, applicant, "doc-1.pdf");
        Files.writeString(uploadDir.resolve("doc-1.pdf"), "pdf");

        when(applicationRepository.findById(10L)).thenReturn(Optional.of(application));
        when(userRepository.findByEmail("applicant@test.com")).thenReturn(Optional.of(applicant));

        var file = service.loadApplicationDocument(10L, "applicant@test.com");
        assertEquals("resume.pdf", file.displayName());
        assertEquals(true, file.resource().exists());
    }

    @Test
    void providerCanDownloadApplicationForOwnOpportunity() throws Exception {
        var provider = user(2L, "provider@test.com", "PROVIDER");
        var applicant = user(1L, "applicant@test.com", "APPLICANT");
        var opportunity = new Opportunity();
        opportunity.setProvider(provider);
        var application = applicationWithDocument(11L, applicant, "doc-2.pdf");
        application.setOpportunity(opportunity);
        Files.writeString(uploadDir.resolve("doc-2.pdf"), "pdf");

        when(applicationRepository.findById(11L)).thenReturn(Optional.of(application));
        when(userRepository.findByEmail("provider@test.com")).thenReturn(Optional.of(provider));

        var file = service.loadApplicationDocument(11L, "provider@test.com");
        assertEquals("resume.pdf", file.displayName());
    }

    @Test
    void unrelatedUserCannotDownloadDocument() {
        var applicant = user(1L, "applicant@test.com", "APPLICANT");
        var stranger = user(9L, "other@test.com", "APPLICANT");
        var application = applicationWithDocument(12L, applicant, "doc-3.pdf");

        when(applicationRepository.findById(12L)).thenReturn(Optional.of(application));
        when(userRepository.findByEmail("other@test.com")).thenReturn(Optional.of(stranger));

        assertThrows(AccessDeniedException.class,
                () -> service.loadApplicationDocument(12L, "other@test.com"));
    }

    @Test
    void missingDocumentThrowsNotFound() {
        var applicant = user(1L, "applicant@test.com", "APPLICANT");
        var application = new Application();
        application.setApplicationId(13L);
        application.setUser(applicant);
        application.setDocumentPath(null);

        when(applicationRepository.findById(13L)).thenReturn(Optional.of(application));
        when(userRepository.findByEmail("applicant@test.com")).thenReturn(Optional.of(applicant));

        assertThrows(ResourceNotFoundException.class,
                () -> service.loadApplicationDocument(13L, "applicant@test.com"));
    }

    private static User user(Long id, String email, String roleName) {
        User user = new User();
        user.setUserId(id);
        user.setEmail(email);
        Role role = new Role();
        role.setRoleName(roleName);
        user.setRole(role);
        return user;
    }

    private static Application applicationWithDocument(Long id, User applicant, String path) {
        Application application = new Application();
        application.setApplicationId(id);
        application.setUser(applicant);
        application.setDocumentPath(path);
        application.setDocumentFilename("resume.pdf");
        return application;
    }
}
