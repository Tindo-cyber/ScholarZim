package com.scholarzim.repository;

import com.scholarzim.entity.Application;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.User;
import org.springframework.data.domain.Pageable;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.util.Collection;
import java.util.List;


public interface ApplicationRepository
        extends JpaRepository<Application, Long> {

    List<Application> findByUser(User user);

    @Query("""
            SELECT a FROM Application a
            JOIN FETCH a.opportunity
            WHERE a.user = :user
            ORDER BY a.submittedAt DESC
            """)
    List<Application> findByUserWithOpportunity(@Param("user") User user);

    @Query("""
            SELECT a FROM Application a
            JOIN FETCH a.user
            JOIN FETCH a.opportunity
            WHERE a.opportunity IN :opportunities
            ORDER BY a.submittedAt DESC
            """)
    List<Application> findByOpportunityInWithDetails(@Param("opportunities") Collection<Opportunity> opportunities);

    @Query("""
            SELECT a.user.userId, COUNT(a)
            FROM Application a
            WHERE a.user.userId IN :userIds
            GROUP BY a.user.userId
            """)
    List<Object[]> countGroupedByUserIds(@Param("userIds") Collection<Long> userIds);

    @Query("""
            SELECT o.provider.userId, COUNT(a)
            FROM Application a
            JOIN a.opportunity o
            WHERE o.provider.userId IN :providerIds
            GROUP BY o.provider.userId
            """)
    List<Object[]> countApplicationsGroupedByProviderIds(@Param("providerIds") Collection<Long> providerIds);

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

    @Query("""
            SELECT EXTRACT(YEAR FROM a.submittedAt), EXTRACT(MONTH FROM a.submittedAt), COUNT(a)
            FROM Application a
            WHERE a.submittedAt IS NOT NULL
            GROUP BY EXTRACT(YEAR FROM a.submittedAt), EXTRACT(MONTH FROM a.submittedAt)
            """)
    List<Object[]> countApplicationsGroupedByYearMonth();

    @Query("""
            SELECT a.applicationStatus, COUNT(a)
            FROM Application a
            WHERE a.applicationStatus IS NOT NULL
            GROUP BY a.applicationStatus
            ORDER BY COUNT(a) DESC
            """)
    List<Object[]> countApplicationsGroupedByStatus();

    @Query("""
            SELECT a FROM Application a
            WHERE LOWER(a.user.email) LIKE LOWER(CONCAT('%', :q, '%'))
               OR LOWER(a.user.fullName) LIKE LOWER(CONCAT('%', :q, '%'))
               OR LOWER(a.opportunity.title) LIKE LOWER(CONCAT('%', :q, '%'))
               OR LOWER(a.applicationStatus) LIKE LOWER(CONCAT('%', :q, '%'))
            ORDER BY a.submittedAt DESC
            """)
    List<Application> adminSearch(@Param("q") String query, Pageable pageable);
}
