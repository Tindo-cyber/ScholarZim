package com.scholarzim.service.impl;

import com.scholarzim.entity.User;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.AuditService;
import com.scholarzim.util.AuditAction;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.mockito.ArgumentCaptor;
import org.springframework.util.MultiValueMap;
import org.springframework.web.client.RestClient;
import org.springframework.web.client.RestClientException;

import java.util.Optional;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.ArgumentMatchers.eq;
import static org.mockito.Mockito.RETURNS_SELF;
import static org.mockito.Mockito.doThrow;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.times;
import static org.mockito.Mockito.verify;
import static org.mockito.Mockito.when;

class MailgunEmailServiceImplTest {

    private RestClient mailgunClient;
    private RestClient.RequestBodyUriSpec requestSpec;
    private RestClient.ResponseSpec responseSpec;
    private AuditService auditService;
    private UserRepository userRepository;
    private MailgunEmailServiceImpl service;

    @BeforeEach
    void setUp() {
        mailgunClient = mock(RestClient.class);
        requestSpec = mock(RestClient.RequestBodyUriSpec.class, RETURNS_SELF);
        responseSpec = mock(RestClient.ResponseSpec.class);

        when(mailgunClient.post()).thenReturn(requestSpec);
        when(requestSpec.uri(eq("/{domain}/messages"), eq("sandbox123.mailgun.org")))
                .thenReturn(requestSpec);
        when(requestSpec.retrieve()).thenReturn(responseSpec);
        when(responseSpec.toBodilessEntity()).thenReturn(null);

        auditService = mock(AuditService.class);
        userRepository = mock(UserRepository.class);
        service = new MailgunEmailServiceImpl(
                mailgunClient,
                auditService,
                userRepository,
                "sandbox123.mailgun.org",
                "noreply@scholarzim.co.zw",
                "ScholarZim",
                3,
                0,
                "http://localhost:8080");
    }

    @Test
    @SuppressWarnings("unchecked")
    void sendPasswordResetEmail_succeedsOnFirstAttempt() {
        service.sendPasswordResetEmail("student@test.com", "http://localhost/reset/token");

        ArgumentCaptor<MultiValueMap<String, String>> captor = ArgumentCaptor.forClass(MultiValueMap.class);
        verify(requestSpec).body(captor.capture());
        MultiValueMap<String, String> form = captor.getValue();
        assertEquals("student@test.com", form.getFirst("to"));
        assertEquals("Reset your ScholarZim password", form.getFirst("subject"));
        assertTrue(form.getFirst("text").contains("http://localhost/reset/token"));
        assertEquals("ScholarZim <noreply@scholarzim.co.zw>", form.getFirst("from"));
        verify(auditService, times(0)).log(any(), any(), any(), any(), any());
    }

    @Test
    void sendPasswordResetEmail_retriesThenSucceeds() {
        when(responseSpec.toBodilessEntity())
                .thenThrow(new RestClientException("temporary failure"))
                .thenReturn(null);

        service.sendPasswordResetEmail("student@test.com", "http://localhost/reset/token");

        verify(responseSpec, times(2)).toBodilessEntity();
        verify(auditService, times(0)).log(any(), any(), any(), any(), any());
    }

    @Test
    void sendPasswordResetEmail_recordsAuditAfterFinalFailure() {
        User user = new User();
        user.setUserId(42L);
        user.setEmail("student@test.com");
        when(userRepository.findByEmail("student@test.com")).thenReturn(Optional.of(user));

        doThrow(new RestClientException("permanent failure"))
                .when(responseSpec)
                .toBodilessEntity();

        service.sendPasswordResetEmail("student@test.com", "http://localhost/reset/token");

        verify(responseSpec, times(3)).toBodilessEntity();
        verify(auditService).log(
                eq("system@scholarzim.co.zw"),
                eq(AuditAction.EMAIL_DELIVERY_FAILED),
                eq("USER"),
                eq(42L),
                eq("Failed to deliver email to student@test.com subject 'Reset your ScholarZim password'"));
    }
}
