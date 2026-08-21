package com.scholarzim.service.impl;

import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.AuditService;
import com.scholarzim.service.EmailService;
import com.scholarzim.util.AuditAction;
import lombok.extern.slf4j.Slf4j;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.context.annotation.Profile;
import org.springframework.http.MediaType;
import org.springframework.scheduling.annotation.Async;
import org.springframework.stereotype.Service;
import org.springframework.util.LinkedMultiValueMap;
import org.springframework.util.MultiValueMap;
import org.springframework.web.client.RestClient;
import org.springframework.web.client.RestClientException;


/**
 * Mailgun-backed EmailService, used in prod only.
 * <p>
 * Render's free tier (and most PaaS free tiers) silently blocks outbound SMTP ports
 * (25/465/587) to prevent spam-relay abuse — the plain SMTP path in {@link EmailServiceImpl}
 * would otherwise hang until connection timeout on every send. Mailgun's API goes over
 * HTTPS/443, which is never blocked, so this is the reliable path for prod.
 */
@Slf4j
@Service
@Profile("prod")
public class MailgunEmailServiceImpl implements EmailService {

    private static final String SYSTEM_ACTOR = "system@scholarzim.co.zw";

    private final RestClient mailgunClient;
    private final AuditService auditService;
    private final UserRepository userRepository;
    private final String domain;
    private final String from;
    private final int maxAttempts;
    private final long retryDelayMs;
    private final String baseUrl;

    public MailgunEmailServiceImpl(
            RestClient mailgunClient,
            AuditService auditService,
            UserRepository userRepository,
            @Value("${scholarzim.mailgun.domain:}") String domain,
            @Value("${scholarzim.mailgun.from-email:noreply@scholarzim.co.zw}") String fromEmail,
            @Value("${scholarzim.mailgun.from-name:ScholarZim}") String fromName,
            @Value("${scholarzim.mail.retry.max-attempts:3}") int maxAttempts,
            @Value("${scholarzim.mail.retry.delay-ms:500}") long retryDelayMs,
            @Value("${scholarzim.app.base-url:http://localhost:8080}") String baseUrl) {

        this.mailgunClient = mailgunClient;
        this.auditService = auditService;
        this.userRepository = userRepository;
        this.domain = domain;
        this.from = "%s <%s>".formatted(fromName, fromEmail);
        this.maxAttempts = Math.max(1, maxAttempts);
        this.retryDelayMs = Math.max(0, retryDelayMs);
        this.baseUrl = baseUrl;
    }

    @Override
    public void sendPasswordResetEmail(String to, String resetLink) {
        send(to, "Reset your ScholarZim password", """
                You requested a password reset for your ScholarZim account.

                Click the link below to set a new password (valid for 1 hour):
                %s

                If you did not request this, ignore this email.
                """.formatted(resetLink));
    }

    @Override
    @Async
    public void sendWelcomeEmail(String to, String name) {
        send(to, "Welcome to ScholarZim", """
                Hi %s,

                Welcome to ScholarZim — Zimbabwe's scholarship platform.

                Complete your profile to unlock personalised scholarship matches:
                %s/applicant/profile

                — The ScholarZim Team
                """.formatted(name, baseUrl));
    }

    @Override
    @Async
    public void sendStatusUpdateEmail(String to, String subject, String body) {
        send(to, subject, body);
    }

    @Override
    @Async
    public void sendEmailVerification(String to, String name, String verifyLink) {
        send(to, "Verify your ScholarZim email", """
                Hi %s,

                Please verify your email address to activate your ScholarZim account:

                %s

                This link expires in 24 hours.

                — The ScholarZim Team
                """.formatted(name, verifyLink));
    }

    @Override
    @Async
    public void sendApplicationSubmittedEmail(String to, String studentName, String scholarshipName, Long applicationId) {
        send(to, "ScholarZim: application received", """
                Hi %s,

                We've received your application for "%s" (reference #%d).

                The provider will review it and you'll be notified of any updates.
                You can track its status anytime under My Applications on ScholarZim:
                %s/my-applications

                — The ScholarZim Team
                """.formatted(studentName, scholarshipName, applicationId, baseUrl));
    }

    private void send(String to, String subject, String text) {
        MultiValueMap<String, String> form = new LinkedMultiValueMap<>();
        form.add("from", from);
        form.add("to", to);
        form.add("subject", subject);
        form.add("text", text);

        for (int attempt = 1; attempt <= maxAttempts; attempt++) {
            try {
                mailgunClient.post()
                        .uri("/{domain}/messages", domain)
                        .contentType(MediaType.APPLICATION_FORM_URLENCODED)
                        .body(form)
                        .retrieve()
                        .toBodilessEntity();
                return;
            } catch (RestClientException ex) {
                if (attempt >= maxAttempts) {
                    log.error("Mailgun email delivery failed after {} attempts to {} subject '{}': {}",
                            maxAttempts, to, subject, ex.getMessage());
                    recordDeliveryFailure(to, subject);
                    return;
                }
                log.warn("Mailgun email attempt {}/{} failed for {}: {}", attempt, maxAttempts, to, ex.getMessage());
                sleepBeforeRetry(attempt);
            }
        }
    }

    private void sleepBeforeRetry(int attempt) {
        if (retryDelayMs <= 0) {
            return;
        }
        try {
            Thread.sleep(retryDelayMs * attempt);
        } catch (InterruptedException ex) {
            Thread.currentThread().interrupt();
        }
    }

    private void recordDeliveryFailure(String recipient, String subject) {
        Long userId = userRepository.findByEmail(recipient)
                .map(user -> user.getUserId())
                .orElse(null);
        auditService.log(
                SYSTEM_ACTOR,
                AuditAction.EMAIL_DELIVERY_FAILED,
                "USER",
                userId,
                "Failed to deliver email to " + recipient + " subject '" + subject + "'");
    }
}
