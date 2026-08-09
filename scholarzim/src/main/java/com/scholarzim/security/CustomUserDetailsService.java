package com.scholarzim.security;

import com.scholarzim.entity.User;
import com.scholarzim.repository.UserRepository;
import org.springframework.security.authentication.DisabledException;
import org.springframework.security.core.GrantedAuthority;
import org.springframework.security.core.authority.SimpleGrantedAuthority;
import org.springframework.security.core.userdetails.*;
import org.springframework.stereotype.Service;

import java.util.ArrayList;
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

        if (!user.isEmailVerified()) {
            throw new DisabledException("Please verify your email before signing in.");
        }

        List<GrantedAuthority> authorities = new ArrayList<>();
        authorities.add(new SimpleGrantedAuthority(user.getRole().getRoleName()));
        if (user.isSuperAdmin()) {
            authorities.add(new SimpleGrantedAuthority("ROLE_SUPER_ADMIN"));
        }

        return org.springframework.security.core.userdetails.User
                .withUsername(user.getEmail())
                .password(user.getPasswordHash())
                .disabled(!statusActive)
                .authorities(authorities)
                .build();
    }
}
