package com.scholarzim.service.impl;

import com.scholarzim.entity.EmailVerificationToken;
import com.scholarzim.entity.User;
import com.scholarzim.repository.EmailVerificationTokenRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.AuditService;
import com.scholarzim.service.EmailService;
import com.scholarzim.service.EmailVerificationService;
import com.scholarzim.util.AuditAction;
import lombok.extern.slf4j.Slf4j;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.security.crypto.password.PasswordEncoder;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.LocalDateTime;
import java.util.UUID;


@Slf4j
@Service
public class EmailVerificationServiceImpl implements EmailVerificationService {

    private final UserRepository userRepository;
    private final EmailVerificationTokenRepository tokenRepository;
    private final EmailService emailService;
    private final AuditService auditService;
    private final PasswordEncoder passwordEncoder;
    private final String baseUrl;

    public EmailVerificationServiceImpl(
            UserRepository userRepository,
            EmailVerificationTokenRepository tokenRepository,
            EmailService emailService,
            AuditService auditService,
            PasswordEncoder passwordEncoder,
            @Value("${scholarzim.app.base-url:http://localhost:8080}") String baseUrl) {

        this.userRepository = userRepository;
        this.tokenRepository = tokenRepository;
        this.emailService = emailService;
        this.auditService = auditService;
        this.passwordEncoder = passwordEncoder;
        this.baseUrl = baseUrl;
    }

    @Override
    @Transactional
    public void issueVerificationToken(User user) {
        tokenRepository.invalidateActiveTokensForUser(user);

        EmailVerificationToken token = new EmailVerificationToken();
        token.setUser(user);
        token.setToken(UUID.randomUUID().toString());
        token.setExpiresAt(LocalDateTime.now().plusHours(24));
        token.setUsed(false);
        tokenRepository.save(token);

        String link = baseUrl + "/verify-email/" + token.getToken();
        emailService.sendEmailVerification(user.getEmail(), user.getFullName(), link);
        auditService.log(user.getEmail(), AuditAction.EMAIL_VERIFICATION_SENT, "USER", user.getUserId(),
                "Verification email issued");
        log.info("Verification email issued for {}", user.getEmail());
    }

    @Override
    @Transactional
    public void verify(String tokenValue) {
        EmailVerificationToken token = tokenRepository.findByTokenAndUsedFalse(tokenValue)
                .orElseThrow(() -> new IllegalArgumentException("Invalid or expired verification link."));

        if (token.getExpiresAt().isBefore(LocalDateTime.now())) {
            throw new IllegalArgumentException("Verification link has expired.");
        }

        User user = token.getUser();
        user.setEmailVerified(true);
        userRepository.save(user);

        token.setUsed(true);
        tokenRepository.save(token);

        auditService.log(user.getEmail(), AuditAction.EMAIL_VERIFIED, "USER", user.getUserId(),
                "Email address verified");
        log.info("Email verified for {}", user.getEmail());
    }

    @Override
    @Transactional
    public void resend(String email) {
        userRepository.findByEmail(email).ifPresent(user -> {
            if (user.isEmailVerified()) {
                return;
            }
            issueVerificationToken(user);
        });
    }

    @Override
    @Transactional(readOnly = true)
    public boolean requiresPasswordSetup(String tokenValue) {
        return tokenRepository.findByTokenAndUsedFalse(tokenValue)
                .map(token -> token.getUser().getPasswordHash() == null)
                .orElse(false);
    }

    @Override
    @Transactional
    public void verifyAndSetPassword(String tokenValue, String newPassword, String confirmPassword) {

        if (newPassword == null || newPassword.isBlank()) {
            throw new IllegalArgumentException("Password is required.");
        }
        if (!newPassword.equals(confirmPassword)) {
            throw new IllegalArgumentException("Passwords do not match.");
        }

        EmailVerificationToken token = tokenRepository.findByTokenAndUsedFalse(tokenValue)
                .orElseThrow(() -> new IllegalArgumentException("Invalid or expired verification link."));

        if (token.getExpiresAt().isBefore(LocalDateTime.now())) {
            throw new IllegalArgumentException("Verification link has expired.");
        }

        User user = token.getUser();
        user.setPasswordHash(passwordEncoder.encode(newPassword));
        user.setEmailVerified(true);
        userRepository.save(user);

        token.setUsed(true);
        tokenRepository.save(token);

        auditService.log(user.getEmail(), AuditAction.EMAIL_VERIFIED, "USER", user.getUserId(),
                "Email verified and password set");
        log.info("Email verified and password set for {}", user.getEmail());
    }
}
