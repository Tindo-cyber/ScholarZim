package com.scholarzim.config;

import com.scholarzim.entity.Role;
import com.scholarzim.entity.User;
import com.scholarzim.repository.RoleRepository;
import com.scholarzim.repository.UserRepository;
import lombok.extern.slf4j.Slf4j;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.boot.CommandLineRunner;
import org.springframework.core.annotation.Order;
import org.springframework.security.crypto.password.PasswordEncoder;
import org.springframework.stereotype.Component;
import org.springframework.transaction.annotation.Transactional;

/**
 * Guarantees a working platform admin exists for FYP demos when demo seed is enabled.
 * Runs before {@link DemoDataSeeder} so admin login works even if later seed steps fail.
 */
@Slf4j
@Component
@Order(1)
public class AdminBootstrap implements CommandLineRunner {

    private static final String ADMIN_EMAIL = "admin@scholarzim.co.zw";
    private static final String ADMIN_PASSWORD = "Password123!";

    private final boolean seedEnabled;
    private final UserRepository userRepository;
    private final RoleRepository roleRepository;
    private final PasswordEncoder passwordEncoder;

    public AdminBootstrap(
            @Value("${scholarzim.demo.seed:true}") boolean seedEnabled,
            UserRepository userRepository,
            RoleRepository roleRepository,
            PasswordEncoder passwordEncoder) {
        this.seedEnabled = seedEnabled;
        this.userRepository = userRepository;
        this.roleRepository = roleRepository;
        this.passwordEncoder = passwordEncoder;
    }

    @Override
    @Transactional
    public void run(String... args) {
        if (!seedEnabled) {
            return;
        }

        Role adminRole = roleRepository.findByRoleName("ROLE_ADMIN").orElseGet(() -> {
            Role role = new Role();
            role.setRoleName("ROLE_ADMIN");
            role.setDescription("Platform administrator");
            return roleRepository.save(role);
        });

        User admin = userRepository.findByEmail(ADMIN_EMAIL).orElseGet(User::new);
        boolean created = admin.getUserId() == null;
        admin.setEmail(ADMIN_EMAIL);
        admin.setFullName("System Administrator");
        admin.setPhone("+263 77 000 0001");
        admin.setPasswordHash(passwordEncoder.encode(ADMIN_PASSWORD));
        admin.setRole(adminRole);
        admin.setAccountStatus("ACTIVE");
        admin.setEmailVerified(true);
        userRepository.save(admin);

        log.info("{} demo admin account {} (password reset to demo value)",
                created ? "Created" : "Refreshed",
                ADMIN_EMAIL);
    }
}
