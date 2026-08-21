package com.scholarzim.repository;

import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.SavedScholarship;
import com.scholarzim.entity.User;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.util.List;
import java.util.Optional;


public interface SavedScholarshipRepository extends JpaRepository<SavedScholarship, Long> {

    List<SavedScholarship> findByUserOrderBySavedAtDesc(User user);

    @Query("""
            SELECT s FROM SavedScholarship s
            JOIN FETCH s.opportunity
            WHERE s.user = :user
            ORDER BY s.savedAt DESC
            """)
    List<SavedScholarship> findByUserWithOpportunityOrderBySavedAtDesc(@Param("user") User user);

    @Query("""
            SELECT o FROM SavedScholarship s
            JOIN s.opportunity o
            WHERE s.user.userId = :userId
            ORDER BY s.savedAt DESC
            """)
    List<Opportunity> findSavedOpportunitiesByUserId(@Param("userId") Long userId);

    @Query("""
            SELECT s.opportunity.opportunityId FROM SavedScholarship s
            WHERE s.user.userId = :userId
            """)
    List<Long> findOpportunityIdsByUserId(@Param("userId") Long userId);

    Optional<SavedScholarship> findByUserAndOpportunityOpportunityId(User user, Long opportunityId);

    boolean existsByUserAndOpportunityOpportunityId(User user, Long opportunityId);

    void deleteByUserAndOpportunityOpportunityId(User user, Long opportunityId);

    List<SavedScholarship> findByOpportunityOpportunityId(Long opportunityId);

    long countByUser(User user);

    @Query("""
            SELECT s.opportunity.opportunityId FROM SavedScholarship s
            WHERE s.user = :user
            """)
    List<Long> findOpportunityIdsByUser(@Param("user") User user);

    @Query("""
            SELECT s.opportunity.title, COUNT(s)
            FROM SavedScholarship s
            WHERE s.opportunity.title IS NOT NULL
            GROUP BY s.opportunity.title
            ORDER BY COUNT(s) DESC
            """)
    List<Object[]> countSavesGroupedByOpportunityTitle();
}
