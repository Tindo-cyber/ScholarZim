package com.scholarzim.service.impl;

import com.scholarzim.dto.RegisterRequest;
import com.scholarzim.entity.Role;
import com.scholarzim.entity.User;
import com.scholarzim.repository.RoleRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.AuditService;
import com.scholarzim.service.AuthService;
import com.scholarzim.service.EmailService;
import com.scholarzim.service.EmailVerificationService;
import com.scholarzim.service.RegistrationException;
import com.scholarzim.util.AuditAction;
import com.scholarzim.util.RoleNames;
import lombok.extern.slf4j.Slf4j;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.security.crypto.password.PasswordEncoder;
import org.springframework.stereotype.Service;


@Slf4j
@Service
public class AuthServiceImpl implements AuthService {

    private final UserRepository userRepository;
    private final RoleRepository roleRepository;
    private final PasswordEncoder passwordEncoder;
    private final AuditService auditService;
    private final EmailService emailService;
    private final EmailVerificationService emailVerificationService;
    private final boolean emailVerificationRequired;

    public AuthServiceImpl(
            UserRepository userRepository,
            RoleRepository roleRepository,
            PasswordEncoder passwordEncoder,
            AuditService auditService,
            EmailService emailService,
            EmailVerificationService emailVerificationService,
            @Value("${scholarzim.auth.email-verification-required:true}") boolean emailVerificationRequired) {

        this.userRepository = userRepository;
        this.roleRepository = roleRepository;
        this.passwordEncoder = passwordEncoder;
        this.auditService = auditService;
        this.emailService = emailService;
        this.emailVerificationService = emailVerificationService;
        this.emailVerificationRequired = emailVerificationRequired;
    }

    @Override
    public void registerApplicant(RegisterRequest request) {

        if (userRepository.existsByEmail(request.getEmail())) {
            throw new RegistrationException("Email already exists");
        }

        Role applicantRole = roleRepository
                .findByRoleName(RoleNames.APPLICANT)
                .orElseThrow(() ->
                        new RegistrationException("Applicant role not found"));

        User user = new User();
        user.setFullName(request.getFullName());
        user.setEmail(request.getEmail());
        user.setPhone(request.getPhone());
        user.setPasswordHash(passwordEncoder.encode(request.getPassword()));
        user.setRole(applicantRole);
        user.setAccountStatus("ACTIVE");
        user.setEmailVerified(!emailVerificationRequired);

        User saved = userRepository.save(user);

        auditService.log(
                request.getEmail(),
                AuditAction.REGISTER,
                "USER",
                saved.getUserId(),
                "New applicant registered: " + request.getFullName());

        log.info("Applicant registered: {}", request.getEmail());

        if (emailVerificationRequired) {
            emailVerificationService.issueVerificationToken(saved);
        } else {
            emailService.sendWelcomeEmail(request.getEmail(), request.getFullName());
        }
    }
}
