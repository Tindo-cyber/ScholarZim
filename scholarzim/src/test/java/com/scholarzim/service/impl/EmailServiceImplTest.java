package com.scholarzim.service.impl;

import com.scholarzim.entity.User;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.AuditService;
import com.scholarzim.util.AuditAction;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.mockito.ArgumentCaptor;
import org.springframework.mail.MailSendException;
import org.springframework.mail.SimpleMailMessage;
import org.springframework.mail.javamail.JavaMailSender;

import java.util.Optional;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.ArgumentMatchers.eq;
import static org.mockito.Mockito.doThrow;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.times;
import static org.mockito.Mockito.verify;
import static org.mockito.Mockito.when;

class EmailServiceImplTest {

    private JavaMailSender mailSender;
    private AuditService auditService;
    private UserRepository userRepository;
    private EmailServiceImpl service;

    @BeforeEach
    void setUp() {
        mailSender = mock(JavaMailSender.class);
        auditService = mock(AuditService.class);
        userRepository = mock(UserRepository.class);
        service = new EmailServiceImpl(
                mailSender,
                auditService,
                userRepository,
                "noreply@scholarzim.co.zw",
                3,
                0);
    }

    @Test
    void sendPasswordResetEmail_succeedsOnFirstAttempt() {
        service.sendPasswordResetEmail("student@test.com", "http://localhost/reset/token");

        ArgumentCaptor<SimpleMailMessage> captor = ArgumentCaptor.forClass(SimpleMailMessage.class);
        verify(mailSender).send(captor.capture());
        SimpleMailMessage message = captor.getValue();
        assertEquals("student@test.com", message.getTo()[0]);
        assertEquals("Reset your ScholarZim password", message.getSubject());
        assertTrue(message.getText().contains("http://localhost/reset/token"));
        verify(auditService, times(0)).log(any(), any(), any(), any(), any());
    }

    @Test
    void sendPasswordResetEmail_retriesThenSucceeds() {
        doThrow(new MailSendException("SMTP down"))
                .doNothing()
                .when(mailSender)
                .send(any(SimpleMailMessage.class));

        service.sendPasswordResetEmail("student@test.com", "http://localhost/reset/token");

        verify(mailSender, times(2)).send(any(SimpleMailMessage.class));
        verify(auditService, times(0)).log(any(), any(), any(), any(), any());
    }

    @Test
    void sendPasswordResetEmail_recordsAuditAfterFinalFailure() {
        User user = new User();
        user.setUserId(42L);
        user.setEmail("student@test.com");
        when(userRepository.findByEmail("student@test.com")).thenReturn(Optional.of(user));

        doThrow(new MailSendException("SMTP down"))
                .when(mailSender)
                .send(any(SimpleMailMessage.class));

        service.sendPasswordResetEmail("student@test.com", "http://localhost/reset/token");

        verify(mailSender, times(3)).send(any(SimpleMailMessage.class));
        verify(auditService).log(
                eq("system@scholarzim.co.zw"),
                eq(AuditAction.EMAIL_DELIVERY_FAILED),
                eq("USER"),
                eq(42L),
                eq("Failed to deliver email to student@test.com subject 'Reset your ScholarZim password'"));
    }
}
