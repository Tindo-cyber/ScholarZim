package com.scholarzim.service.impl;

import com.scholarzim.dto.ApplicationSubmitRequest;
import com.scholarzim.entity.Application;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.Role;
import com.scholarzim.entity.User;
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

import java.time.LocalDate;
import java.util.Optional;

import static org.junit.jupiter.api.Assertions.assertDoesNotThrow;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.when;

class ApplicationServiceImplApplyGateTest {

    @TempDir
    java.nio.file.Path uploadDir;

    private ApplicationRepository applicationRepository;
    private UserRepository userRepository;
    private OpportunityRepository opportunityRepository;
    private ApplicantProfileService applicantProfileService;
    private ApplicationServiceImpl service;

    @BeforeEach
    void setUp() {
        applicationRepository = mock(ApplicationRepository.class);
        userRepository = mock(UserRepository.class);
        opportunityRepository = mock(OpportunityRepository.class);
        applicantProfileService = mock(ApplicantProfileService.class);
        service = new ApplicationServiceImpl(
                applicationRepository,
                userRepository,
                opportunityRepository,
                mock(NotificationService.class),
                mock(AuditService.class),
                new FileStorageService(uploadDir.toString()),
                applicantProfileService);
    }

    @Test
    void applyWithoutCertificateThrows() {
        when(applicantProfileService.hasResultsCertificate("student@test.com")).thenReturn(false);

        assertThrows(IllegalStateException.class,
                () -> service.apply(1L, "student@test.com"));
    }

    @Test
    void submitApplicationWithoutCertificateThrows() {
        when(applicantProfileService.hasResultsCertificate("student@test.com")).thenReturn(false);

        ApplicationSubmitRequest request = new ApplicationSubmitRequest();
        request.setOpportunityId(1L);
        request.setPersonalStatement("I am motivated.");

        assertThrows(IllegalStateException.class,
                () -> service.submitApplication(request, null, "student@test.com"));
    }

    @Test
    void applyWithCertificateProceeds() {
        User user = user(1L, "ready@test.com");
        Opportunity opportunity = activeOpportunity(10L);

        when(applicantProfileService.hasResultsCertificate("ready@test.com")).thenReturn(true);
        when(opportunityRepository.findById(10L)).thenReturn(Optional.of(opportunity));
        when(userRepository.findByEmail("ready@test.com")).thenReturn(Optional.of(user));
        when(applicationRepository.existsByUserAndOpportunity(user, opportunity)).thenReturn(false);
        when(applicationRepository.save(any())).thenAnswer(inv -> {
            Application saved = inv.getArgument(0);
            saved.setApplicationId(99L);
            return saved;
        });

        assertDoesNotThrow(() -> service.apply(10L, "ready@test.com"));
    }

    private static User user(Long id, String email) {
        User user = new User();
        user.setUserId(id);
        user.setEmail(email);
        Role role = new Role();
        role.setRoleName("ROLE_APPLICANT");
        user.setRole(role);
        return user;
    }

    private static Opportunity activeOpportunity(Long id) {
        Opportunity opportunity = new Opportunity();
        opportunity.setOpportunityId(id);
        opportunity.setTitle("Test Scholarship");
        opportunity.setStatus("ACTIVE");
        opportunity.setDeadline(LocalDate.now().plusMonths(1));
        return opportunity;
    }
}
