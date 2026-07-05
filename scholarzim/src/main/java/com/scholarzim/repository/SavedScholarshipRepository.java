package com.scholarzim.repository;

import com.scholarzim.entity.SavedScholarship;
import com.scholarzim.entity.User;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.List;
import java.util.Optional;


public interface SavedScholarshipRepository extends JpaRepository<SavedScholarship, Long> {

    List<SavedScholarship> findByUserOrderBySavedAtDesc(User user);

    Optional<SavedScholarship> findByUserAndOpportunityOpportunityId(User user, Long opportunityId);

    boolean existsByUserAndOpportunityOpportunityId(User user, Long opportunityId);

    void deleteByUserAndOpportunityOpportunityId(User user, Long opportunityId);

    List<SavedScholarship> findByOpportunityOpportunityId(Long opportunityId);
}
