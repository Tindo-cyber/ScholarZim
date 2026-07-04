package com.scholarzim.service.impl;

import com.scholarzim.entity.User;
import com.scholarzim.exception.ResourceNotFoundException;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.TotpService;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import dev.samstevens.totp.code.*;
import dev.samstevens.totp.secret.DefaultSecretGenerator;
import dev.samstevens.totp.secret.SecretGenerator;
import dev.samstevens.totp.time.SystemTimeProvider;
import dev.samstevens.totp.time.TimeProvider;

@Service
public class TotpServiceImpl implements TotpService {

    private static final String ISSUER = "ScholarZim";

    private final UserRepository userRepository;
    private final SecretGenerator secretGenerator = new DefaultSecretGenerator();
    private final TimeProvider timeProvider = new SystemTimeProvider();
    private final CodeGenerator codeGenerator = new DefaultCodeGenerator();
    private final CodeVerifier verifier = new DefaultCodeVerifier(codeGenerator, timeProvider);

    public TotpServiceImpl(UserRepository userRepository) {
        this.userRepository = userRepository;
    }

    @Override
    public String generateSecret() {
        return secretGenerator.generate();
    }

    @Override
    public String buildQrUri(String email, String secret) {
        return "otpauth://totp/" + ISSUER + ":" + email + "?secret=" + secret + "&issuer=" + ISSUER;
    }

    @Override
    public boolean verify(String secret, String code) {
        if (secret == null || code == null) {
            return false;
        }
        return verifier.isValidCode(secret, code);
    }

    @Override
    @Transactional
    public void enableForUser(String email, String secret, String code) {

        if (!verify(secret, code)) {
            throw new IllegalArgumentException("Invalid verification code");
        }

        User user = userRepository.findByEmail(email)
                .orElseThrow(() -> new ResourceNotFoundException("User not found"));
        user.setTotpSecret(secret);
        user.setTotpEnabled(true);
        userRepository.save(user);
    }

    @Override
    @Transactional
    public void disableForUser(String email) {

        User user = userRepository.findByEmail(email)
                .orElseThrow(() -> new ResourceNotFoundException("User not found"));
        user.setTotpSecret(null);
        user.setTotpEnabled(false);
        userRepository.save(user);
    }

    @Override
    public boolean requiresTwoFactor(String email) {
        return userRepository.findByEmail(email)
                .map(User::isTotpEnabled)
                .orElse(false);
    }

    @Override
    public boolean verifyForUser(String email, String code) {
        User user = userRepository.findByEmail(email).orElse(null);
        if (user == null || !user.isTotpEnabled() || user.getTotpSecret() == null) {
            return false;
        }
        return verify(user.getTotpSecret(), code);
    }
}
