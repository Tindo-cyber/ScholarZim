package com.scholarzim.service;

import com.scholarzim.dto.ScoredOpportunityDTO;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.User;

import java.util.List;


public interface RecommendationService {

    List<ScoredOpportunityDTO> recommendForApplicant(String email);

    /**
     * Returns the applicants whose profile matches the given opportunity (match score &gt; 0).
     */
    List<User> findMatchingApplicants(Opportunity opportunity);
}
