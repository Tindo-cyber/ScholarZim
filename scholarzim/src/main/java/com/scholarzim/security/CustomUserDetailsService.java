package com.scholarzim.security;

import com.scholarzim.entity.User;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.util.RoleNames;
import org.springframework.security.authentication.DisabledException;
import org.springframework.security.core.authority.SimpleGrantedAuthority;
import org.springframework.security.core.userdetails.*;
import org.springframework.stereotype.Service;

import java.util.List;


@Service
public class CustomUserDetailsService implements UserDetailsService {

    private final UserRepository userRepository;

    public CustomUserDetailsService(UserRepository userRepository) {
        this.userRepository = userRepository;
    }

    @Override
    public UserDetails loadUserByUsername(String email)
            throws UsernameNotFoundException {

        User user = userRepository.findByEmailWithRole(email)
                .orElseThrow(() ->
                        new UsernameNotFoundException("User not found"));

        if (user.getRole() == null) {
            throw new UsernameNotFoundException("User role not configured");
        }

        boolean statusActive = user.getAccountStatus() == null
                || "ACTIVE".equalsIgnoreCase(user.getAccountStatus());

        boolean emailOk = user.isEmailVerified()
                || user.getRole().getRoleName() == null
                || !RoleNames.APPLICANT.equals(user.getRole().getRoleName());

        if (!emailOk) {
            throw new DisabledException("Please verify your email before signing in.");
        }

        return org.springframework.security.core.userdetails.User
                .withUsername(user.getEmail())
                .password(user.getPasswordHash())
                .disabled(!statusActive)
                .authorities(List.of(new SimpleGrantedAuthority(
                        user.getRole().getRoleName())))
                .build();
    }
}
