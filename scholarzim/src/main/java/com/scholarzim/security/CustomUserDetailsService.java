package com.scholarzim.security;

import com.scholarzim.entity.User;
import com.scholarzim.repository.UserRepository;
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

        User user = userRepository.findByEmail(email)
                .orElseThrow(() ->
                        new UsernameNotFoundException("User not found"));

        boolean enabled = user.getAccountStatus() == null
                || "ACTIVE".equalsIgnoreCase(user.getAccountStatus());

        if (user.getRole() == null) {
            throw new UsernameNotFoundException("User role not configured");
        }

        return org.springframework.security.core.userdetails.User
                .withUsername(user.getEmail())
                .password(user.getPasswordHash())
                .disabled(!enabled)
                .authorities(List.of(new SimpleGrantedAuthority(
                        user.getRole().getRoleName())))
                .build();
    }
}