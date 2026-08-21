package com.scholarzim.service.impl;

import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.AuditService;
import com.scholarzim.service.EmailService;
import com.scholarzim.util.AuditAction;
import lombok.extern.slf4j.Slf4j;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.context.annotation.Profile;
import org.springframework.mail.SimpleMailMessage;
import org.springframework.mail.javamail.JavaMailSender;
import org.springframework.scheduling.annotation.Async;
import org.springframework.stereotype.Service;


/**
 * SMTP-backed EmailService, used everywhere except prod (local dev/demo route through
 * the Mailhog container; prod uses {@link MailgunEmailServiceImpl} instead, since Render's
 * free tier blocks outbound SMTP — see MailgunEmailServiceImpl for details).
 */
@Slf4j
@Service
@Profile("!prod")
public class EmailServiceImpl implements EmailService {

    private static final String SYSTEM_ACTOR = "system@scholarzim.co.zw";

    private final JavaMailSender mailSender;
    private final AuditService auditService;
    private final UserRepository userRepository;
    private final String fromAddress;
    private final int maxAttempts;
    private final long retryDelayMs;
    private final String baseUrl;

    public EmailServiceImpl(
            JavaMailSender mailSender,
            AuditService auditService,
            UserRepository userRepository,
            @Value("${scholarzim.mail.from:noreply@scholarzim.co.zw}") String fromAddress,
            @Value("${scholarzim.mail.retry.max-attempts:3}") int maxAttempts,
            @Value("${scholarzim.mail.retry.delay-ms:500}") long retryDelayMs,
            @Value("${scholarzim.app.base-url:http://localhost:8080}") String baseUrl) {

        this.mailSender = mailSender;
        this.auditService = auditService;
        this.userRepository = userRepository;
        this.fromAddress = fromAddress;
        this.maxAttempts = Math.max(1, maxAttempts);
        this.retryDelayMs = Math.max(0, retryDelayMs);
        this.baseUrl = baseUrl;
    }

    @Override
    public void sendPasswordResetEmail(String to, String resetLink) {
        SimpleMailMessage message = new SimpleMailMessage();
        message.setFrom(fromAddress);
        message.setTo(to);
        message.setSubject("Reset your ScholarZim password");
        message.setText("""
                You requested a password reset for your ScholarZim account.

                Click the link below to set a new password (valid for 1 hour):
                %s

                If you did not request this, ignore this email.
                """.formatted(resetLink));
        sendWithRetry(message);
    }

    @Override
    @Async
    public void sendWelcomeEmail(String to, String name) {
        SimpleMailMessage message = new SimpleMailMessage();
        message.setFrom(fromAddress);
        message.setTo(to);
        message.setSubject("Welcome to ScholarZim");
        message.setText("""
                Hi %s,

                Welcome to ScholarZim — Zimbabwe's scholarship platform.

                Complete your profile to unlock personalised scholarship matches:
                %s/applicant/profile

                — The ScholarZim Team
                """.formatted(name, baseUrl));
        sendWithRetry(message);
    }

    @Override
    @Async
    public void sendStatusUpdateEmail(String to, String subject, String body) {
        SimpleMailMessage message = new SimpleMailMessage();
        message.setFrom(fromAddress);
        message.setTo(to);
        message.setSubject(subject);
        message.setText(body);
        sendWithRetry(message);
    }

    @Override
    @Async
    public void sendEmailVerification(String to, String name, String verifyLink) {
        SimpleMailMessage message = new SimpleMailMessage();
        message.setFrom(fromAddress);
        message.setTo(to);
        message.setSubject("Verify your ScholarZim email");
        message.setText("""
                Hi %s,

                Please verify your email address to activate your ScholarZim account:

                %s

                This link expires in 24 hours.

                — The ScholarZim Team
                """.formatted(name, verifyLink));
        sendWithRetry(message);
    }

    @Override
    @Async
    public void sendApplicationSubmittedEmail(String to, String studentName, String scholarshipName, Long applicationId) {
        SimpleMailMessage message = new SimpleMailMessage();
        message.setFrom(fromAddress);
        message.setTo(to);
        message.setSubject("ScholarZim: application received");
        message.setText("""
                Hi %s,

                We've received your application for "%s" (reference #%d).

                The provider will review it and you'll be notified of any updates.
                You can track its status anytime under My Applications on ScholarZim:
                %s/my-applications

                — The ScholarZim Team
                """.formatted(studentName, scholarshipName, applicationId, baseUrl));
        sendWithRetry(message);
    }

    private void sendWithRetry(SimpleMailMessage message) {
        String recipient = message.getTo() != null && message.getTo().length > 0
                ? message.getTo()[0]
                : "unknown";
        String subject = message.getSubject() != null ? message.getSubject() : "(no subject)";

        for (int attempt = 1; attempt <= maxAttempts; attempt++) {
            try {
                mailSender.send(message);
                return;
            } catch (Exception ex) {
                if (attempt >= maxAttempts) {
                    log.error("Email delivery failed after {} attempts to {} subject '{}': {}",
                            maxAttempts, recipient, subject, ex.getMessage());
                    recordDeliveryFailure(recipient, subject);
                    return;
                }
                log.warn("Email attempt {}/{} failed for {}: {}", attempt, maxAttempts, recipient, ex.getMessage());
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
