package com.scholarzim.service.impl;

import com.scholarzim.service.EmailService;
import lombok.extern.slf4j.Slf4j;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.mail.SimpleMailMessage;
import org.springframework.mail.javamail.JavaMailSender;
import org.springframework.stereotype.Service;

@Slf4j
@Service
public class EmailServiceImpl implements EmailService {

    private final JavaMailSender mailSender;
    private final String fromAddress;

    public EmailServiceImpl(
            JavaMailSender mailSender,
            @Value("${scholarzim.mail.from:noreply@scholarzim.co.zw}") String fromAddress) {

        this.mailSender = mailSender;
        this.fromAddress = fromAddress;
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

        try {
            mailSender.send(message);
        } catch (Exception ex) {
            log.warn("Failed to send password reset email to {}: {}", to, ex.getMessage());
        }
    }

    @Override
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

        try {
            mailSender.send(message);
        } catch (Exception ex) {
            log.warn("Failed to send welcome email to {}: {}", to, ex.getMessage());
        }
    }

    @Override
    public void sendStatusUpdateEmail(String to, String subject, String body) {

        SimpleMailMessage message = new SimpleMailMessage();
        message.setFrom(fromAddress);
        message.setTo(to);
        message.setSubject(subject);
        message.setText(body);

        try {
            mailSender.send(message);
        } catch (Exception ex) {
            log.warn("Failed to send status email to {}: {}", to, ex.getMessage());
        }
    }
}
