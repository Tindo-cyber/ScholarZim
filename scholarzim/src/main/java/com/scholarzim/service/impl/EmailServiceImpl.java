package com.scholarzim.service.impl;

import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.AuditService;
import com.scholarzim.service.EmailService;
import com.scholarzim.util.AuditAction;
import lombok.extern.slf4j.Slf4j;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.mail.SimpleMailMessage;
import org.springframework.mail.javamail.JavaMailSender;
import org.springframework.scheduling.annotation.Async;
import org.springframework.stereotype.Service;


@Slf4j
@Service
public class EmailServiceImpl implements EmailService {

    private static final String SYSTEM_ACTOR = "system@scholarzim.co.zw";

    private final JavaMailSender mailSender;
    private final AuditService auditService;
    private final UserRepository userRepository;
    private final String fromAddress;
    private final int maxAttempts;
    private final long retryDelayMs;

    public EmailServiceImpl(
            JavaMailSender mailSender,
            AuditService auditService,
            UserRepository userRepository,
            @Value("${scholarzim.mail.from:noreply@scholarzim.co.zw}") String fromAddress,
            @Value("${scholarzim.mail.retry.max-attempts:3}") int maxAttempts,
            @Value("${scholarzim.mail.retry.delay-ms:500}") long retryDelayMs) {

        this.mailSender = mailSender;
        this.auditService = auditService;
        this.userRepository = userRepository;
        this.fromAddress = fromAddress;
        this.maxAttempts = Math.max(1, maxAttempts);
        this.retryDelayMs = Math.max(0, retryDelayMs);
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

                Complete your profile to unlock personalised scholarship matches.

                — The ScholarZim Team
                """.formatted(name));
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
