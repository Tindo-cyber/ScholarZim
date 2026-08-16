package com.scholarzim.service.impl;

import com.resend.Resend;
import com.resend.core.exception.ResendException;
import com.resend.services.emails.Emails;
import com.resend.services.emails.model.CreateEmailOptions;
import com.scholarzim.entity.User;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.AuditService;
import com.scholarzim.util.AuditAction;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.mockito.ArgumentCaptor;

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

class ResendEmailServiceImplTest {

    private Resend resend;
    private Emails emails;
    private AuditService auditService;
    private UserRepository userRepository;
    private ResendEmailServiceImpl service;

    @BeforeEach
    void setUp() {
        resend = mock(Resend.class);
        emails = mock(Emails.class);
        when(resend.emails()).thenReturn(emails);
        auditService = mock(AuditService.class);
        userRepository = mock(UserRepository.class);
        service = new ResendEmailServiceImpl(
                resend,
                auditService,
                userRepository,
                "noreply@scholarzim.co.zw",
                "ScholarZim",
                3,
                0);
    }

    @Test
    void sendPasswordResetEmail_succeedsOnFirstAttempt() throws ResendException {
        service.sendPasswordResetEmail("student@test.com", "http://localhost/reset/token");

        ArgumentCaptor<CreateEmailOptions> captor = ArgumentCaptor.forClass(CreateEmailOptions.class);
        verify(emails).send(captor.capture());
        CreateEmailOptions params = captor.getValue();
        assertEquals("student@test.com", params.getTo().get(0));
        assertEquals("Reset your ScholarZim password", params.getSubject());
        assertTrue(params.getHtml().contains("http://localhost/reset/token"));
        assertEquals("ScholarZim <noreply@scholarzim.co.zw>", params.getFrom());
        verify(auditService, times(0)).log(any(), any(), any(), any(), any());
    }

    @Test
    void sendPasswordResetEmail_retriesThenSucceeds() throws ResendException {
        doThrow(new ResendException("temporary failure"))
                .doReturn(null)
                .when(emails)
                .send(any(CreateEmailOptions.class));

        service.sendPasswordResetEmail("student@test.com", "http://localhost/reset/token");

        verify(emails, times(2)).send(any(CreateEmailOptions.class));
        verify(auditService, times(0)).log(any(), any(), any(), any(), any());
    }

    @Test
    void sendPasswordResetEmail_recordsAuditAfterFinalFailure() throws ResendException {
        User user = new User();
        user.setUserId(42L);
        user.setEmail("student@test.com");
        when(userRepository.findByEmail("student@test.com")).thenReturn(Optional.of(user));

        doThrow(new ResendException("permanent failure"))
                .when(emails)
                .send(any(CreateEmailOptions.class));

        service.sendPasswordResetEmail("student@test.com", "http://localhost/reset/token");

        verify(emails, times(3)).send(any(CreateEmailOptions.class));
        verify(auditService).log(
                eq("system@scholarzim.co.zw"),
                eq(AuditAction.EMAIL_DELIVERY_FAILED),
                eq("USER"),
                eq(42L),
                eq("Failed to deliver email to student@test.com subject 'Reset your ScholarZim password'"));
    }
}
