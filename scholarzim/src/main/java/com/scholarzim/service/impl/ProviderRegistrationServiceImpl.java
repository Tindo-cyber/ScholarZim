package com.scholarzim.service.impl;

import com.scholarzim.dto.RegisterRequest;
import com.scholarzim.entity.Role;
import com.scholarzim.entity.User;
import com.scholarzim.repository.RoleRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.AuditService;
import com.scholarzim.service.ProviderRegistrationService;
import com.scholarzim.service.RegistrationException;
import com.scholarzim.util.AuditAction;
import org.springframework.security.crypto.password.PasswordEncoder;
import org.springframework.stereotype.Service;

@Service
public class ProviderRegistrationServiceImpl implements ProviderRegistrationService {

    private final UserRepository userRepository;
    private final RoleRepository roleRepository;
    private final PasswordEncoder passwordEncoder;
    private final AuditService auditService;

    public ProviderRegistrationServiceImpl(
            UserRepository userRepository,
            RoleRepository roleRepository,
            PasswordEncoder passwordEncoder,
            AuditService auditService) {

        this.userRepository = userRepository;
        this.roleRepository = roleRepository;
        this.passwordEncoder = passwordEncoder;
        this.auditService = auditService;
    }

    @Override
    public void registerProvider(RegisterRequest request) {

        if (userRepository.existsByEmail(request.getEmail())) {
            throw new RegistrationException("Email already exists");
        }

        if (!request.getPassword().equals(request.getConfirmPassword())) {
            throw new RegistrationException("Passwords do not match");
        }

        Role providerRole = roleRepository.findByRoleName("ROLE_PROVIDER")
                .orElseThrow(() -> new RegistrationException("Provider role not found"));

        User user = new User();
        user.setFullName(request.getFullName());
        user.setEmail(request.getEmail());
        user.setPhone(request.getPhone());
        user.setPasswordHash(passwordEncoder.encode(request.getPassword()));
        user.setRole(providerRole);
        user.setAccountStatus("PENDING_APPROVAL");

        User saved = userRepository.save(user);

        auditService.log(request.getEmail(), AuditAction.REGISTER, "USER", saved.getUserId(),
                "Provider registration pending approval: " + request.getFullName());
    }
}
