package com.scholarzim.config;

import com.scholarzim.security.CspNonceHeaderWriter;
import com.scholarzim.security.RoleBasedAuthenticationSuccessHandler;
import com.scholarzim.security.ScholarzimAuthenticationFailureHandler;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.http.HttpMethod;
import org.springframework.security.authentication.AuthenticationManager;
import org.springframework.security.config.annotation.authentication.configuration.AuthenticationConfiguration;
import org.springframework.security.config.annotation.web.builders.HttpSecurity;
import org.springframework.security.web.SecurityFilterChain;
import org.springframework.security.web.csrf.CookieCsrfTokenRepository;


@Configuration
public class SecurityConfig {

    private final RoleBasedAuthenticationSuccessHandler successHandler;
    private final ScholarzimAuthenticationFailureHandler failureHandler;

    public SecurityConfig(
            RoleBasedAuthenticationSuccessHandler successHandler,
            ScholarzimAuthenticationFailureHandler failureHandler) {

        this.successHandler = successHandler;
        this.failureHandler = failureHandler;
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
                                "/verify-email/**",
                                "/resend-verification",
                                "/403",
                                "/error",
                                "/css/**",
                                "/js/**",
                                "/images/**",
                                "/icons/**",
                                // React shell + bundle. The SPA is public; the data
                                // behind it stays guarded by the /api/** rules below.
                                "/app/**",
                                "/manifest.json",
                                "/sw.js",
                                "/api/public/**",
                                // Returns authenticated=false when anonymous, so it must
                                // resolve rather than 401 — the SPA calls it on every load.
                                "/api/me",
                                "/v3/api-docs/**",
                                "/swagger-ui/**",
                                "/swagger-ui.html",
                                "/actuator/health"
                        ).permitAll()
                        .requestMatchers(
                                "/provider/**",
                                "/opportunities/create"
                        ).hasRole("PROVIDER")
                        .requestMatchers(HttpMethod.GET, "/opportunities").authenticated()
                        .requestMatchers("/admin/**").hasRole("ADMIN")
                        .requestMatchers(
                                "/my-applications",
                                "/applicant/**",
                                "/apply/**"
                        ).hasRole("APPLICANT")
                        .requestMatchers("/account/**").authenticated()
                        .requestMatchers("/applications/*/document").authenticated()
                        .requestMatchers("/applications/*/results-certificate").authenticated()
                        .requestMatchers("/api/applicant/**").hasRole("APPLICANT")
                        .requestMatchers("/api/**").authenticated()
                        .requestMatchers("/dashboard").authenticated()
                        .anyRequest().authenticated()
                )
                // Public JSON is GET-only; session-authenticated applicant APIs keep CSRF.
                // The token is stored in a JS-readable cookie so the React app can echo
                // it back as X-XSRF-TOKEN. Thymeleaf forms are unaffected — they still
                // render the token through CsrfModelAdvice regardless of where it lives.
                .csrf(csrf -> csrf
                        .csrfTokenRepository(CookieCsrfTokenRepository.withHttpOnlyFalse())
                        .ignoringRequestMatchers("/api/public/**"))
                .formLogin(form -> form
                        .loginPage("/login")
                        .successHandler(successHandler)
                        .failureHandler(failureHandler)
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
                        .addHeaderWriter(new CspNonceHeaderWriter())
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
