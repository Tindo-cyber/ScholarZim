package com.scholarzim.service.impl;

import com.scholarzim.entity.Application;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.Role;
import com.scholarzim.entity.User;
import com.scholarzim.repository.ApplicationRepository;
import com.scholarzim.repository.OpportunityRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.ApplicantProfileService;
import com.scholarzim.service.AuditService;
import com.scholarzim.service.EmailService;
import com.scholarzim.service.FileStorageService;
import com.scholarzim.service.NotificationService;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;

import java.util.Optional;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.ArgumentMatchers.anyString;
import static org.mockito.ArgumentMatchers.contains;
import static org.mockito.ArgumentMatchers.eq;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.never;
import static org.mockito.Mockito.verify;
import static org.mockito.Mockito.when;

class ApplicationServiceImplStatusReasonTest {

    @TempDir
    java.nio.file.Path uploadDir;

    private ApplicationRepository applicationRepository;
    private UserRepository userRepository;
    private EmailService emailService;
    private AuditService auditService;
    private ApplicationServiceImpl service;

    @BeforeEach
    void setUp() {
        applicationRepository = mock(ApplicationRepository.class);
        userRepository = mock(UserRepository.class);
        emailService = mock(EmailService.class);
        auditService = mock(AuditService.class);

        service = new ApplicationServiceImpl(
                applicationRepository,
                userRepository,
                mock(OpportunityRepository.class),
                mock(NotificationService.class),
                auditService,
                new FileStorageService(uploadDir.toString()),
                mock(ApplicantProfileService.class),
                emailService);
    }

    @Test
    void approvingWithoutReasonThrows() {
        Application application = applicationFor(1L);
        when(applicationRepository.findByIdWithDetails(1L)).thenReturn(Optional.of(application));
        when(userRepository.findByEmail("provider@test.com"))
                .thenReturn(Optional.of(application.getOpportunity().getProvider()));

        assertThrows(IllegalArgumentException.class,
                () -> service.updateStatus(1L, "APPROVED", null, "provider@test.com"));
        assertThrows(IllegalArgumentException.class,
                () -> service.updateStatus(1L, "APPROVED", "   ", "provider@test.com"));

        verify(applicationRepository, never()).save(any());
    }

    @Test
    void rejectingWithoutReasonThrows() {
        Application application = applicationFor(2L);
        when(applicationRepository.findByIdWithDetails(2L)).thenReturn(Optional.of(application));
        when(userRepository.findByEmail("provider@test.com"))
                .thenReturn(Optional.of(application.getOpportunity().getProvider()));

        assertThrows(IllegalArgumentException.class,
                () -> service.updateStatus(2L, "REJECTED", "", "provider@test.com"));

        verify(applicationRepository, never()).save(any());
    }

    @Test
    void approvingWithReasonStoresItAndEmailsApplicant() {
        Application application = applicationFor(3L);
        when(applicationRepository.findByIdWithDetails(3L)).thenReturn(Optional.of(application));
        when(userRepository.findByEmail("provider@test.com"))
                .thenReturn(Optional.of(application.getOpportunity().getProvider()));

        service.updateStatus(3L, "APPROVED", "Strong academic record", "provider@test.com");

        assertEquals("Strong academic record", application.getRejectionReason());
        verify(applicationRepository).save(application);
        verify(emailService).sendStatusUpdateEmail(
                eq("applicant@test.com"), anyString(), contains("Strong academic record"));
        verify(auditService).log(eq("provider@test.com"), anyString(), eq("APPLICATION"), eq(3L),
                contains("Strong academic record"));
    }

    @Test
    void rejectingWithReasonStoresItAndEmailsApplicant() {
        Application application = applicationFor(4L);
        when(applicationRepository.findByIdWithDetails(4L)).thenReturn(Optional.of(application));
        when(userRepository.findByEmail("provider@test.com"))
                .thenReturn(Optional.of(application.getOpportunity().getProvider()));

        service.updateStatus(4L, "REJECTED", "Does not meet eligibility criteria", "provider@test.com");

        assertEquals("Does not meet eligibility criteria", application.getRejectionReason());
        verify(emailService).sendStatusUpdateEmail(
                eq("applicant@test.com"), anyString(), contains("Does not meet eligibility criteria"));
    }

    @Test
    void approvingSkipsEmailWhenApplicantOptedOut() {
        Application application = applicationFor(6L);
        application.getUser().setEmailNotifyApplications(false);
        when(applicationRepository.findByIdWithDetails(6L)).thenReturn(Optional.of(application));
        when(userRepository.findByEmail("provider@test.com"))
                .thenReturn(Optional.of(application.getOpportunity().getProvider()));

        service.updateStatus(6L, "APPROVED", "Strong academic record", "provider@test.com");

        verify(emailService, never()).sendStatusUpdateEmail(anyString(), anyString(), anyString());
    }

    @Test
    void markingUnderReviewDoesNotRequireReason() {
        Application application = applicationFor(5L);
        when(applicationRepository.findByIdWithDetails(5L)).thenReturn(Optional.of(application));
        when(userRepository.findByEmail("provider@test.com"))
                .thenReturn(Optional.of(application.getOpportunity().getProvider()));

        service.updateStatus(5L, "UNDER_REVIEW", "provider@test.com");

        assertEquals("UNDER_REVIEW", application.getApplicationStatus());
        verify(emailService, never()).sendStatusUpdateEmail(anyString(), anyString(), anyString());
    }

    private static Application applicationFor(Long id) {
        User provider = new User();
        provider.setUserId(100L);
        provider.setEmail("provider@test.com");
        Role providerRole = new Role();
        providerRole.setRoleName("ROLE_PROVIDER");
        provider.setRole(providerRole);

        User applicant = new User();
        applicant.setUserId(200L);
        applicant.setEmail("applicant@test.com");
        applicant.setFullName("Test Applicant");

        Opportunity opportunity = new Opportunity();
        opportunity.setOpportunityId(500L);
        opportunity.setTitle("Test Scholarship");
        opportunity.setProvider(provider);

        Application application = new Application();
        application.setApplicationId(id);
        application.setUser(applicant);
        application.setOpportunity(opportunity);
        application.setApplicationStatus("SUBMITTED");
        return application;
    }
}
