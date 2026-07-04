package com.scholarzim.service.impl;

import com.scholarzim.dto.OpportunityRequest;
import com.scholarzim.entity.User;
import com.scholarzim.repository.OpportunityRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.AuditService;
import com.scholarzim.service.NotificationService;
import com.scholarzim.service.RecommendationService;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.springframework.security.access.AccessDeniedException;

import java.time.LocalDate;
import java.util.Optional;

import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.never;
import static org.mockito.Mockito.verify;
import static org.mockito.Mockito.when;

class OpportunityServiceImplProviderStatusTest {

    private UserRepository userRepository;
    private OpportunityRepository opportunityRepository;
    private OpportunityServiceImpl service;

    @BeforeEach
    void setUp() {
        userRepository = mock(UserRepository.class);
        opportunityRepository = mock(OpportunityRepository.class);
        service = new OpportunityServiceImpl(
                opportunityRepository,
                userRepository,
                mock(RecommendationService.class),
                mock(NotificationService.class),
                mock(AuditService.class));
    }

    @Test
    void pendingProviderCannotCreateOpportunity() {
        User provider = new User();
        provider.setEmail("pending@org.co.zw");
        provider.setAccountStatus("PENDING_APPROVAL");
        when(userRepository.findByEmail("pending@org.co.zw")).thenReturn(Optional.of(provider));

        OpportunityRequest request = new OpportunityRequest();
        request.setTitle("Test Scholarship");
        request.setDescription("Desc");
        request.setEducationLevel("Undergraduate");
        request.setFundingType("Full Scholarship");
        request.setCountry("Zimbabwe");
        request.setDeadline(LocalDate.now().plusMonths(3));

        assertThrows(AccessDeniedException.class,
                () -> service.createOpportunity(request, "pending@org.co.zw"));

        verify(opportunityRepository, never()).save(any());
    }
}
