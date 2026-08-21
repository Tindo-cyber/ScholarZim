package com.scholarzim.security;

import com.scholarzim.entity.User;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.AuditService;
import com.scholarzim.util.AuditAction;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.springframework.security.authentication.UsernamePasswordAuthenticationToken;
import org.springframework.security.core.authority.SimpleGrantedAuthority;

import java.util.List;
import java.util.Optional;

import static org.mockito.ArgumentMatchers.any;
import static org.mockito.ArgumentMatchers.anyLong;
import static org.mockito.ArgumentMatchers.anyString;
import static org.mockito.ArgumentMatchers.eq;
import static org.mockito.Mockito.doThrow;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.verify;
import static org.mockito.Mockito.when;

class RoleBasedAuthenticationSuccessHandlerTest {

    private AuditService auditService;
    private UserRepository userRepository;
    private RoleBasedAuthenticationSuccessHandler handler;
    private HttpServletRequest request;
    private HttpServletResponse response;

    @BeforeEach
    void setUp() {
        auditService = mock(AuditService.class);
        userRepository = mock(UserRepository.class);
        handler = new RoleBasedAuthenticationSuccessHandler(auditService, userRepository);
        request = mock(HttpServletRequest.class);
        response = mock(HttpServletResponse.class);
        when(request.getContextPath()).thenReturn("");
    }

    @Test
    void loginStillRedirectsWhenAuditWriteFails() throws Exception {
        User user = new User();
        user.setUserId(7L);
        user.setEmail("student@example.com");
        when(userRepository.findByEmailWithRole("student@example.com")).thenReturn(Optional.of(user));
        doThrow(new RuntimeException("Aiven connection lost"))
                .when(auditService).log(anyString(), anyString(), anyString(), anyLong(), anyString());

        var auth = new UsernamePasswordAuthenticationToken(
                "student@example.com",
                "n/a",
                List.of(new SimpleGrantedAuthority("ROLE_APPLICANT")));

        handler.onAuthenticationSuccess(request, response, auth);

        verify(response).sendRedirect("/applicant/dashboard");
        verify(auditService).log(
                eq("student@example.com"),
                eq(AuditAction.LOGIN_SUCCESS),
                eq("USER"),
                eq(7L),
                any());
    }
}
