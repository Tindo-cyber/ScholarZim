package com.scholarzim.service.impl;

import com.resend.Resend;
import com.resend.core.exception.ResendException;
import com.resend.services.emails.model.CreateEmailOptions;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.AuditService;
import com.scholarzim.service.EmailService;
import com.scholarzim.util.AuditAction;
import lombok.extern.slf4j.Slf4j;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.context.annotation.Profile;
import org.springframework.scheduling.annotation.Async;
import org.springframework.stereotype.Service;

/**
 * Resend-backed EmailService, used in prod only.
 * <p>
 * Render's free tier (and most PaaS free tiers) silently blocks outbound SMTP ports
 * (25/465/587) to prevent spam-relay abuse — the plain SMTP path in {@link EmailServiceImpl}
 * would otherwise hang until connection timeout on every send. Resend's API goes over
 * HTTPS/443, which is never blocked, so this is the reliable path for prod.
 */
@Slf4j
@Service
@Profile("prod")
public class ResendEmailServiceImpl implements EmailService {

    private static final String SYSTEM_ACTOR = "system@scholarzim.co.zw";

    private final Resend resend;
    private final AuditService auditService;
    private final UserRepository userRepository;
    private final String from;
    private final int maxAttempts;
    private final long retryDelayMs;

    public ResendEmailServiceImpl(
            Resend resend,
            AuditService auditService,
            UserRepository userRepository,
            @Value("${resend.from-email:noreply@scholarzim.co.zw}") String fromEmail,
            @Value("${resend.from-name:ScholarZim}") String fromName,
            @Value("${scholarzim.mail.retry.max-attempts:3}") int maxAttempts,
            @Value("${scholarzim.mail.retry.delay-ms:500}") long retryDelayMs) {

        this.resend = resend;
        this.auditService = auditService;
        this.userRepository = userRepository;
        this.from = "%s <%s>".formatted(fromName, fromEmail);
        this.maxAttempts = Math.max(1, maxAttempts);
        this.retryDelayMs = Math.max(0, retryDelayMs);
    }

    @Override
    public void sendPasswordResetEmail(String to, String resetLink) {
        CreateEmailOptions params = CreateEmailOptions.builder()
                .from(from)
                .to(to)
                .subject("Reset your ScholarZim password")
                .html("""
                        <p>You requested a password reset for your ScholarZim account.</p>
                        <p><a href="%s">Click here to set a new password</a> (valid for 1 hour).</p>
                        <p>If you did not request this, ignore this email.</p>
                        """.formatted(resetLink))
                .build();
        send(params, to, "Reset your ScholarZim password");
    }

    @Override
    @Async
    public void sendWelcomeEmail(String to, String name) {
        CreateEmailOptions params = CreateEmailOptions.builder()
                .from(from)
                .to(to)
                .subject("Welcome to ScholarZim")
                .html("""
                        <p>Hi %s,</p>
                        <p>Welcome to ScholarZim — Zimbabwe's scholarship platform.</p>
                        <p>Complete your profile to unlock personalised scholarship matches.</p>
                        <p>— The ScholarZim Team</p>
                        """.formatted(name))
                .build();
        send(params, to, "Welcome to ScholarZim");
    }

    @Override
    @Async
    public void sendStatusUpdateEmail(String to, String subject, String body) {
        CreateEmailOptions params = CreateEmailOptions.builder()
                .from(from)
                .to(to)
                .subject(subject)
                .html("<div style=\"white-space:pre-wrap;\">" + escapeHtml(body) + "</div>")
                .build();
        send(params, to, subject);
    }

    @Override
    @Async
    public void sendEmailVerification(String to, String name, String verifyLink) {
        CreateEmailOptions params = CreateEmailOptions.builder()
                .from(from)
                .to(to)
                .subject("Verify your ScholarZim email")
                .html("""
                        <p>Hi %s,</p>
                        <p>Please verify your email address to activate your ScholarZim account:</p>
                        <p><a href="%s">%s</a></p>
                        <p>This link expires in 24 hours.</p>
                        <p>— The ScholarZim Team</p>
                        """.formatted(name, verifyLink, verifyLink))
                .build();
        send(params, to, "Verify your ScholarZim email");
    }

    @Override
    @Async
    public void sendApplicationSubmittedEmail(String to, String studentName, String scholarshipName, Long applicationId) {
        CreateEmailOptions params = CreateEmailOptions.builder()
                .from(from)
                .to(to)
                .subject("ScholarZim: application received")
                .html("""
                        <p>Hi %s,</p>
                        <p>We've received your application for "%s" (reference #%d).</p>
                        <p>The provider will review it and you'll be notified of any updates.
                        You can track its status anytime under My Applications on ScholarZim.</p>
                        <p>— The ScholarZim Team</p>
                        """.formatted(studentName, scholarshipName, applicationId))
                .build();
        send(params, to, "ScholarZim: application received");
    }

    private void send(CreateEmailOptions params, String recipient, String subject) {
        for (int attempt = 1; attempt <= maxAttempts; attempt++) {
            try {
                resend.emails().send(params);
                return;
            } catch (ResendException ex) {
                if (attempt >= maxAttempts) {
                    log.error("Resend email delivery failed after {} attempts to {} subject '{}': {}",
                            maxAttempts, recipient, subject, ex.getMessage());
                    recordDeliveryFailure(recipient, subject);
                    return;
                }
                log.warn("Resend email attempt {}/{} failed for {}: {}", attempt, maxAttempts, recipient, ex.getMessage());
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

    private static String escapeHtml(String value) {
        return value == null ? "" : value
                .replace("&", "&amp;")
                .replace("<", "&lt;")
                .replace(">", "&gt;");
    }
}
