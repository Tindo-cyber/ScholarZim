package com.scholarzim.service.impl;

import com.scholarzim.dto.AdminSearchResultsDTO;
import com.scholarzim.entity.Application;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.Role;
import com.scholarzim.entity.User;
import com.scholarzim.repository.ApplicationRepository;
import com.scholarzim.repository.OpportunityRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.util.ApplicationStatus;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.springframework.data.domain.PageRequest;

import java.util.List;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.ArgumentMatchers.eq;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.verifyNoInteractions;
import static org.mockito.Mockito.when;

class AdminSearchServiceImplTest {

    private UserRepository userRepository;
    private OpportunityRepository opportunityRepository;
    private ApplicationRepository applicationRepository;
    private AdminSearchServiceImpl service;

    @BeforeEach
    void setUp() {
        userRepository = mock(UserRepository.class);
        opportunityRepository = mock(OpportunityRepository.class);
        applicationRepository = mock(ApplicationRepository.class);
        service = new AdminSearchServiceImpl(
                userRepository, opportunityRepository, applicationRepository);
    }

    @Test
    void shortQueryReturnsEmptyWithoutRepositoryCalls() {
        AdminSearchResultsDTO results = service.search("a");

        assertTrue(results.isEmpty());
        assertEquals("a", results.getQuery());
        verifyNoInteractions(userRepository, opportunityRepository, applicationRepository);
    }

    @Test
    void searchAggregatesHitsFromAllRepositories() {
        User user = new User();
        user.setFullName("Simba Moyo");
        user.setEmail("simba@test.co.zw");
        Role role = new Role();
        role.setRoleName("ROLE_APPLICANT");
        user.setRole(role);

        Opportunity opportunity = new Opportunity();
        opportunity.setOpportunityId(5L);
        opportunity.setTitle("STEM Bursary");
        opportunity.setProviderName("UZ");
        opportunity.setStatus("ACTIVE");

        Application application = new Application();
        application.setApplicationStatus(ApplicationStatus.SUBMITTED);
        application.setUser(user);
        application.setOpportunity(opportunity);

        when(userRepository.adminSearch(eq("simba"), any(PageRequest.class)))
                .thenReturn(List.of(user));
        when(opportunityRepository.adminSearchByKeyword(eq("simba"), any(PageRequest.class)))
                .thenReturn(List.of());
        when(applicationRepository.adminSearch(eq("simba"), any(PageRequest.class)))
                .thenReturn(List.of(application));

        AdminSearchResultsDTO results = service.search("simba");

        assertEquals(2, results.getTotalCount());
        assertEquals(1, results.getUsers().size());
        assertEquals("Student", results.getUsers().get(0).getBadge());
        assertEquals(1, results.getApplications().size());
        assertEquals("/scholarships/5", results.getApplications().get(0).getLink());
    }
}
