package com.scholarzim.service;

import com.scholarzim.entity.Opportunity;
import org.springframework.lang.NonNull;

import java.util.List;
import java.util.Set;


public interface SavedScholarshipService {

    void save(String email, @NonNull Long opportunityId);

    void remove(String email, @NonNull Long opportunityId);

    boolean isSaved(String email, @NonNull Long opportunityId);

    List<Opportunity> listSaved(String email);

    Set<Long> listSavedOpportunityIds(String email);

    long countSaved(String email);
}
