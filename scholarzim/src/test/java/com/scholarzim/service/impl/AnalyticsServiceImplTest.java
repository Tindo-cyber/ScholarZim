package com.scholarzim.service.impl;

import com.scholarzim.dto.ChartData;
import com.scholarzim.repository.ApplicantProfileRepository;
import com.scholarzim.repository.ApplicationRepository;
import com.scholarzim.repository.AuditLogRepository;
import com.scholarzim.repository.OpportunityRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.util.ApplicationStatus;
import com.scholarzim.util.RoleNames;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

import java.util.List;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.when;

class AnalyticsServiceImplTest {

    private UserRepository userRepository;
    private OpportunityRepository opportunityRepository;
    private ApplicationRepository applicationRepository;
    private AnalyticsServiceImpl service;

    @BeforeEach
    void setUp() {
        userRepository = mock(UserRepository.class);
        opportunityRepository = mock(OpportunityRepository.class);
        applicationRepository = mock(ApplicationRepository.class);
        service = new AnalyticsServiceImpl(
                userRepository,
                opportunityRepository,
                applicationRepository,
                mock(ApplicantProfileRepository.class),
                mock(AuditLogRepository.class));
    }

    @Test
    void applicationStatusBreakdownUsesFriendlyLabels() {
        when(applicationRepository.countApplicationsGroupedByStatus()).thenReturn(List.of(
                new Object[]{ApplicationStatus.APPROVED, 4L},
                new Object[]{ApplicationStatus.SUBMITTED, 2L}));

        ChartData chart = service.getApplicationStatusBreakdown();

        assertEquals(List.of("Approved", "Submitted"), chart.getLabels());
        assertEquals(List.of(4L, 2L), chart.getData());
    }

    @Test
    void userRoleBreakdownIncludesAllRoles() {
        when(userRepository.countByRoleRoleName(RoleNames.APPLICANT)).thenReturn(10L);
        when(userRepository.countByRoleRoleName(RoleNames.PROVIDER)).thenReturn(3L);
        when(userRepository.countByRoleRoleName(RoleNames.ADMIN)).thenReturn(1L);

        ChartData chart = service.getUserRoleBreakdown();

        assertEquals(List.of("Students", "Providers", "Admins"), chart.getLabels());
        assertEquals(List.of(10L, 3L, 1L), chart.getData());
    }

    @Test
    void scholarshipAvailabilityBreakdown() {
        when(opportunityRepository.countByStatus("ACTIVE")).thenReturn(7L);
        when(opportunityRepository.count()).thenReturn(10L);

        ChartData chart = service.getScholarshipAvailabilityBreakdown();

        assertEquals(List.of("Open", "Closed / inactive"), chart.getLabels());
        assertEquals(List.of(7L, 3L), chart.getData());
    }
}
