package com.scholarzim.service;

import com.scholarzim.dto.OpportunityRequest;
import com.scholarzim.dto.OpportunitySearchRequest;
import com.scholarzim.entity.Opportunity;
import org.springframework.lang.NonNull;

import java.util.List;


public interface OpportunityService {

    void createOpportunity(OpportunityRequest request, String providerEmail);

    List<Opportunity> getAllOpportunities();

    List<Opportunity> getActiveOpportunities();

    List<Opportunity> getFeaturedOpportunities(int limit);

    long countActiveOpportunities();

    long countUpcomingDeadlines();

    List<Opportunity> searchOpportunities(OpportunitySearchRequest searchRequest);

    List<String> getProviderNames();

    java.util.Optional<Opportunity> findById(@NonNull Long id);
}
