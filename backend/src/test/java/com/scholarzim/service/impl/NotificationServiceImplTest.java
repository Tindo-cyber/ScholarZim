package com.scholarzim.service.impl;

import com.scholarzim.entity.User;
import com.scholarzim.repository.NotificationRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.EmailService;
import com.scholarzim.util.NotificationType;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

import static org.mockito.ArgumentMatchers.anyString;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.never;
import static org.mockito.Mockito.verify;

class NotificationServiceImplTest {

    private NotificationServiceImpl service;
    private EmailService emailService;

    @BeforeEach
    void setUp() {
        emailService = mock(EmailService.class);
        service = new NotificationServiceImpl(
                mock(NotificationRepository.class),
                mock(UserRepository.class),
                emailService,
                "http://localhost:8080");
    }

    @Test
    void emailsWhenTypeIsEligibleAndPreferenceIsOn() {
        User user = applicant("student@test.com");
        user.setEmailNotifyApplications(true);

        service.notifyUser(user, NotificationType.APPLICATION_SUBMITTED, "Submitted", "/my-applications", 1L);

        verify(emailService).sendStatusUpdateEmail(anyString(), anyString(), anyString());
    }

    @Test
    void skipsEmailWhenPreferenceIsOff() {
        User user = applicant("student@test.com");
        user.setEmailNotifyApplications(false);

        service.notifyUser(user, NotificationType.APPLICATION_SUBMITTED, "Submitted", "/my-applications", 1L);

        verify(emailService, never()).sendStatusUpdateEmail(anyString(), anyString(), anyString());
    }

    @Test
    void skipsEmailForApprovedAndRejectedEvenWithPreferenceOn() {
        // ApplicationServiceImpl sends a detailed email for these two decisions itself;
        // notifyUser must not also send its own generic email, or the applicant gets two.
        User user = applicant("student@test.com");
        user.setEmailNotifyApplications(true);

        service.notifyUser(user, NotificationType.APPLICATION_APPROVED, "Approved", "/my-applications", 1L);
        service.notifyUser(user, NotificationType.APPLICATION_REJECTED, "Rejected", "/my-applications", 2L);

        verify(emailService, never()).sendStatusUpdateEmail(anyString(), anyString(), anyString());
    }

    private static User applicant(String email) {
        User user = new User();
        user.setUserId(1L);
        user.setEmail(email);
        user.setFullName("Test Student");
        return user;
    }
}
