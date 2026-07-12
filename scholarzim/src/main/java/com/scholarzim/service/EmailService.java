package com.scholarzim.service;

public interface EmailService {

    void sendPasswordResetEmail(String to, String resetLink);

    void sendWelcomeEmail(String to, String name);

    void sendStatusUpdateEmail(String to, String subject, String body);

    void sendEmailVerification(String to, String name, String verifyLink);
}
