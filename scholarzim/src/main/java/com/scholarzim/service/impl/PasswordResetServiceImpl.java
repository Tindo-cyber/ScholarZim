package com.scholarzim.service.impl;

import com.scholarzim.entity.PasswordResetToken;
import com.scholarzim.entity.User;
import com.scholarzim.repository.PasswordResetTokenRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.AuditService;
import com.scholarzim.service.EmailService;
import com.scholarzim.service.PasswordResetService;
import com.scholarzim.util.AuditAction;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.security.crypto.password.PasswordEncoder;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.LocalDateTime;
import java.util.UUID;


@Service
public class PasswordResetServiceImpl implements PasswordResetService {

    private final UserRepository userRepository;
    private final PasswordResetTokenRepository tokenRepository;
    private final PasswordEncoder passwordEncoder;
    private final EmailService emailService;
    private final AuditService auditService;
    private final String baseUrl;

    public PasswordResetServiceImpl(
            UserRepository userRepository,
            PasswordResetTokenRepository tokenRepository,
            PasswordEncoder passwordEncoder,
            EmailService emailService,
            AuditService auditService,
            @Value("${scholarzim.app.base-url:http://localhost:8080}") String baseUrl) {

        this.userRepository = userRepository;
        this.tokenRepository = tokenRepository;
        this.passwordEncoder = passwordEncoder;
        this.emailService = emailService;
        this.auditService = auditService;
        this.baseUrl = baseUrl;
    }

    @Override
    @Transactional
    public void requestReset(String email) {

        userRepository.findByEmail(email).ifPresent(user -> {
            PasswordResetToken token = new PasswordResetToken();
            token.setUser(user);
            token.setToken(UUID.randomUUID().toString());
            token.setExpiresAt(LocalDateTime.now().plusHours(1));
            token.setUsed(false);
            tokenRepository.save(token);
            emailService.sendPasswordResetEmail(email, baseUrl + "/reset-password/" + token.getToken());
            auditService.log(email, AuditAction.PASSWORD_RESET_REQUEST, "USER", user.getUserId(),
                    "Password reset requested");
        });
    }

    @Override
    @Transactional
    public void resetPassword(String tokenValue, String newPassword, String confirmPassword) {

        if (!newPassword.equals(confirmPassword)) {
            throw new IllegalArgumentException("Passwords do not match.");
        }

        if (newPassword.length() < 8) {
            throw new IllegalArgumentException("Password must be at least 8 characters.");
        }

        PasswordResetToken token = tokenRepository.findByTokenAndUsedFalse(tokenValue)
                .orElseThrow(() -> new IllegalArgumentException("Invalid or expired reset link."));

        if (token.getExpiresAt().isBefore(LocalDateTime.now())) {
            throw new IllegalArgumentException("Reset link has expired.");
        }

        User user = token.getUser();
        user.setPasswordHash(passwordEncoder.encode(newPassword));
        userRepository.save(user);

        token.setUsed(true);
        tokenRepository.save(token);

        auditService.log(user.getEmail(), AuditAction.PASSWORD_RESET_COMPLETE, "USER", user.getUserId(),
                "Password reset completed");
    }
}
