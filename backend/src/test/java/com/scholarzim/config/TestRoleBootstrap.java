package com.scholarzim.config;

import com.scholarzim.entity.Role;
import com.scholarzim.repository.RoleRepository;
import org.springframework.boot.test.context.TestConfiguration;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Profile;

import java.util.List;


@TestConfiguration
@Profile("test")
public class TestRoleBootstrap {

    @Bean
    RoleBootstrapRunner roleBootstrapRunner(RoleRepository roleRepository) {
        return new RoleBootstrapRunner(roleRepository);
    }

    static class RoleBootstrapRunner implements org.springframework.boot.CommandLineRunner {

        private final RoleRepository roleRepository;

        RoleBootstrapRunner(RoleRepository roleRepository) {
            this.roleRepository = roleRepository;
        }

        @Override
        public void run(String... args) {
            List<String[]> roles = List.of(
                    new String[]{"ROLE_APPLICANT", "Scholarship applicant"},
                    new String[]{"ROLE_PROVIDER", "Scholarship provider"},
                    new String[]{"ROLE_ADMIN", "Platform administrator"}
            );
            for (String[] def : roles) {
                roleRepository.findByRoleName(def[0]).orElseGet(() -> {
                    Role role = new Role();
                    role.setRoleName(def[0]);
                    role.setDescription(def[1]);
                    return roleRepository.save(role);
                });
            }
        }
    }
}
