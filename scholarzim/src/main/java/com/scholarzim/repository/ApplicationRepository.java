package com.scholarzim.repository;

import com.scholarzim.entity.Application;
import com.scholarzim.entity.User;
import com.scholarzim.entity.Opportunity;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Query;

import java.util.Collection;
import java.util.List;

public interface ApplicationRepository
        extends JpaRepository<Application, Long> {

    List<Application> findByUser(User user);

    List<Application> findByOpportunity(Opportunity opportunity);

    List<Application> findByOpportunityIn(Collection<Opportunity> opportunities);

    void deleteByUser(User user);

    void deleteByOpportunity(Opportunity opportunity);

    boolean existsByUserAndOpportunity(User user, Opportunity opportunity);

    long countByApplicationStatus(String applicationStatus);

    @Query("""
            SELECT a.opportunity.providerName, COUNT(a)
            FROM Application a
            WHERE a.opportunity.providerName IS NOT NULL
            GROUP BY a.opportunity.providerName
            ORDER BY COUNT(a) DESC
            """)
    List<Object[]> countApplicationsByProvider();

    @Query("""
            SELECT a.opportunity.title, COUNT(a)
            FROM Application a
            WHERE a.opportunity.title IS NOT NULL
            GROUP BY a.opportunity.title
            ORDER BY COUNT(a) DESC
            """)
    List<Object[]> countApplicationsByOpportunity();
}
