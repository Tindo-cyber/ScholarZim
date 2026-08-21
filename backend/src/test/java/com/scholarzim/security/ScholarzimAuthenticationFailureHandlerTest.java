package com.scholarzim.security;

import com.scholarzim.entity.Role;
import com.scholarzim.entity.User;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.AuditService;
import com.scholarzim.util.AuditAction;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.springframework.security.authentication.BadCredentialsException;
import org.springframework.security.authentication.DisabledException;
import org.springframework.security.web.RedirectStrategy;

import java.util.Optional;

import static org.mockito.ArgumentMatchers.any;
import static org.mockito.ArgumentMatchers.anyLong;
import static org.mockito.ArgumentMatchers.anyString;
import static org.mockito.ArgumentMatchers.contains;
import static org.mockito.ArgumentMatchers.eq;
import static org.mockito.Mockito.doThrow;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.verify;
import static org.mockito.Mockito.when;

class ScholarzimAuthenticationFailureHandlerTest {

    private UserRepository userRepository;
    private AuditService auditService;
    private ScholarzimAuthenticationFailureHandler handler;
    private HttpServletRequest request;
    private HttpServletResponse response;
    private RedirectStrategy redirectStrategy;

    @BeforeEach
    void setUp() throws Exception {
        userRepository = mock(UserRepository.class);
        auditService = mock(AuditService.class);
        handler = new ScholarzimAuthenticationFailureHandler(userRepository, auditService);
        request = mock(HttpServletRequest.class);
        response = mock(HttpServletResponse.class);
        redirectStrategy = mock(RedirectStrategy.class);
        handler.setRedirectStrategy(redirectStrategy);
    }

    @Test
    void auditFailureDoesNotBlockRedirect() throws Exception {
        when(request.getParameter("username")).thenReturn("student@example.com");
        when(userRepository.findByEmailWithRole("student@example.com")).thenReturn(Optional.empty());
        doThrow(new RuntimeException("audit down"))
                .when(auditService).log(anyString(), anyString(), anyString(), any(), anyString());

        handler.onAuthenticationFailure(request, response, new BadCredentialsException("bad"));

        verify(redirectStrategy).sendRedirect(eq(request), eq(response), contains("/login?error=credentials"));
    }

    @Test
    void unverifiedEmailMapsToUnverifiedError() throws Exception {
        when(request.getParameter("username")).thenReturn("student@example.com");
        User user = new User();
        Role role = new Role();
        role.setRoleName("ROLE_APPLICANT");
        user.setRole(role);
        user.setAccountStatus("ACTIVE");
        when(userRepository.findByEmailWithRole("student@example.com")).thenReturn(Optional.of(user));

        handler.onAuthenticationFailure(
                request, response, new DisabledException("Please verify your email before signing in."));

        verify(redirectStrategy).sendRedirect(eq(request), eq(response), contains("error=unverified"));
    }
}
