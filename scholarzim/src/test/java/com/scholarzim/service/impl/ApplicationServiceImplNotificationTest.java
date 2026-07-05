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
import com.scholarzim.util.NotificationType;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;

import java.time.LocalDate;
import java.util.Optional;

import static org.mockito.ArgumentMatchers.any;
import static org.mockito.ArgumentMatchers.eq;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.verify;
import static org.mockito.Mockito.when;

class ApplicationServiceImplNotificationTest {

    @TempDir
    java.nio.file.Path uploadDir;

    private NotificationService notificationService;
    private ApplicationServiceImpl service;

    @BeforeEach
    void setUp() {
        ApplicationRepository applicationRepository = mock(ApplicationRepository.class);
        UserRepository userRepository = mock(UserRepository.class);
        OpportunityRepository opportunityRepository = mock(OpportunityRepository.class);
        ApplicantProfileService applicantProfileService = mock(ApplicantProfileService.class);
        notificationService = mock(NotificationService.class);

        service = new ApplicationServiceImpl(
                applicationRepository,
                userRepository,
                opportunityRepository,
                notificationService,
                mock(AuditService.class),
                new FileStorageService(uploadDir.toString()),
                applicantProfileService);

        User applicant = applicant(1L, "student@test.com", "Tanaka Moyo");
        User provider = provider(2L, "provider@test.com", "UK Scholarships");
        Opportunity opportunity = opportunity(10L, "STEM Grant", provider);

        when(applicantProfileService.hasResultsCertificate("student@test.com")).thenReturn(true);
        when(opportunityRepository.findById(10L)).thenReturn(Optional.of(opportunity));
        when(userRepository.findByEmail("student@test.com")).thenReturn(Optional.of(applicant));
        when(applicationRepository.existsByUserAndOpportunity(applicant, opportunity)).thenReturn(false);
        when(applicationRepository.save(any())).thenAnswer(inv -> {
            Application saved = inv.getArgument(0);
            saved.setApplicationId(99L);
            return saved;
        });
    }

    @Test
    void submitNotifiesApplicantAndProvider() {
        ApplicationSubmitRequest request = new ApplicationSubmitRequest();
        request.setOpportunityId(10L);
        request.setPersonalStatement("I am a strong candidate with clear goals for my community.");

        service.submitApplication(request, null, "student@test.com");

        verify(notificationService).notifyUser(
                any(User.class),
                eq(NotificationType.APPLICATION_SUBMITTED),
                eq("Your application for \"STEM Grant\" was submitted successfully."),
                eq("/my-applications"),
                eq(99L));

        verify(notificationService).notifyUser(
                any(User.class),
                eq(NotificationType.NEW_APPLICATION),
                eq("Tanaka Moyo applied to \"STEM Grant\"."),
                eq("/provider/applications/99"),
                eq(99L));
    }

    private static User applicant(Long id, String email, String name) {
        User user = new User();
        user.setUserId(id);
        user.setEmail(email);
        user.setFullName(name);
        Role role = new Role();
        role.setRoleName("ROLE_APPLICANT");
        user.setRole(role);
        return user;
    }

    private static User provider(Long id, String email, String name) {
        User user = new User();
        user.setUserId(id);
        user.setEmail(email);
        user.setFullName(name);
        Role role = new Role();
        role.setRoleName("ROLE_PROVIDER");
        user.setRole(role);
        return user;
    }

    private static Opportunity opportunity(Long id, String title, User provider) {
        Opportunity opportunity = new Opportunity();
        opportunity.setOpportunityId(id);
        opportunity.setTitle(title);
        opportunity.setStatus("ACTIVE");
        opportunity.setDeadline(LocalDate.now().plusMonths(1));
        opportunity.setProvider(provider);
        return opportunity;
    }
}
