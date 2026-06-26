package com.scholarzim.config;

import com.scholarzim.security.RoleBasedAuthenticationSuccessHandler;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.security.authentication.AuthenticationManager;
import org.springframework.security.config.annotation.authentication.configuration.AuthenticationConfiguration;
import org.springframework.security.config.annotation.web.builders.HttpSecurity;
import org.springframework.security.web.SecurityFilterChain;

@Configuration
public class SecurityConfig {

    private final RoleBasedAuthenticationSuccessHandler successHandler;

    public SecurityConfig(RoleBasedAuthenticationSuccessHandler successHandler) {
        this.successHandler = successHandler;
    }

    @Bean
    public SecurityFilterChain securityFilterChain(HttpSecurity http)
            throws Exception {

        http
                .authorizeHttpRequests(auth -> auth
                        .requestMatchers(
                                "/",
                                "/scholarships",
                                "/scholarships/**",
                                "/register",
                                "/register/provider",
                                "/login",
                                "/forgot-password",
                                "/reset-password/**",
                                "/403",
                                "/css/**",
                                "/js/**",
                                "/images/**",
                                "/icons/**",
                                "/manifest.json",
                                "/sw.js",
                                "/uploads/**",
                                "/api/public/**",
                                "/v3/api-docs/**",
                                "/swagger-ui/**",
                                "/swagger-ui.html",
                                "/actuator/health"
                        ).permitAll()
                        .requestMatchers(
                                "/provider/**",
                                "/opportunities/create"
                        ).hasRole("PROVIDER")
                        .requestMatchers("/admin/**").hasRole("ADMIN")
                        .requestMatchers(
                                "/my-applications",
                                "/applicant/**",
                                "/opportunities",
                                "/apply/**"
                        ).hasRole("APPLICANT")
                        .requestMatchers("/account/**").authenticated()
                        .requestMatchers("/api/applicant/**").hasRole("APPLICANT")
                        .requestMatchers("/api/**").authenticated()
                        .requestMatchers("/dashboard").authenticated()
                        .anyRequest().authenticated()
                )
                .csrf(csrf -> csrf.ignoringRequestMatchers("/api/**"))
                .formLogin(form -> form
                        .loginPage("/login")
                        .successHandler(successHandler)
                        .permitAll()
                )
                .logout(logout -> logout
                        .logoutSuccessUrl("/login?logout")
                )
                .exceptionHandling(ex -> ex
                        .accessDeniedPage("/403")
                )
                .headers(headers -> headers
                        .frameOptions(frame -> frame.sameOrigin())
                        .contentSecurityPolicy(csp -> csp
                                .policyDirectives("default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'"))
                );

        return http.build();
    }

    @Bean
    public AuthenticationManager authenticationManager(
            AuthenticationConfiguration configuration)
            throws Exception {

        return configuration.getAuthenticationManager();
    }
}
