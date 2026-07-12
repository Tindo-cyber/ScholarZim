package com.scholarzim.repository;

import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.User;
import org.springframework.data.domain.Pageable;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.time.LocalDate;
import java.util.Collection;
import java.util.List;


public interface OpportunityRepository
        extends JpaRepository<Opportunity, Long> {

    List<Opportunity> findByProvider(User provider);

    @Query("""
            SELECT o.provider.userId, COUNT(o)
            FROM Opportunity o
            WHERE o.provider.userId IN :providerIds
            GROUP BY o.provider.userId
            """)
    List<Object[]> countGroupedByProviderIds(@Param("providerIds") Collection<Long> providerIds);

    @Query("""
            SELECT o FROM Opportunity o
            WHERE o.status = 'ACTIVE'
              AND (o.deadline IS NULL OR o.deadline >= :today)
              AND (:educationLevel IS NULL OR :educationLevel = '' OR o.educationLevel = :educationLevel)
              AND (:country IS NULL OR :country = '' OR o.country = :country OR o.targetCountry = :country)
              AND (:fieldOfStudy IS NULL OR :fieldOfStudy = '' OR o.targetField = :fieldOfStudy)
              AND (:provider IS NULL OR :provider = '' OR o.providerName = :provider)
              AND (:fundingType IS NULL OR :fundingType = '' OR o.fundingType = :fundingType)
              AND (:deadlineBefore IS NULL OR o.deadline <= :deadlineBefore)
            ORDER BY o.createdAt DESC
            """)
    List<Opportunity> search(
            @Param("today") LocalDate today,
            @Param("educationLevel") String educationLevel,
            @Param("country") String country,
            @Param("fieldOfStudy") String fieldOfStudy,
            @Param("provider") String provider,
            @Param("fundingType") String fundingType,
            @Param("deadlineBefore") LocalDate deadlineBefore);

    @Query("""
            SELECT DISTINCT o.providerName FROM Opportunity o
            WHERE o.providerName IS NOT NULL AND o.providerName <> ''
            ORDER BY o.providerName
            """)
    List<String> findDistinctProviderNames();

    long countByStatus(String status);

    List<Opportunity> findByStatusAndDeadlineBetween(
            String status, LocalDate fromInclusive, LocalDate toInclusive);

    @Query("""
            SELECT o FROM Opportunity o
            WHERE o.status = 'ACTIVE'
              AND (o.deadline IS NULL OR o.deadline >= :today)
              AND (:educationLevel IS NULL OR :educationLevel = '' OR o.educationLevel = :educationLevel)
              AND (:country IS NULL OR :country = '' OR o.country = :country OR o.targetCountry = :country)
              AND (:fieldOfStudy IS NULL OR :fieldOfStudy = '' OR o.targetField = :fieldOfStudy)
              AND (:provider IS NULL OR :provider = '' OR o.providerName = :provider)
              AND (:fundingType IS NULL OR :fundingType = '' OR o.fundingType = :fundingType)
              AND (:deadlineBefore IS NULL OR o.deadline <= :deadlineBefore)
              AND (:keyword IS NULL OR :keyword = ''
                   OR LOWER(o.title) LIKE LOWER(CONCAT('%', :keyword, '%'))
                   OR LOWER(o.description) LIKE LOWER(CONCAT('%', :keyword, '%'))
                   OR LOWER(o.providerName) LIKE LOWER(CONCAT('%', :keyword, '%')))
            ORDER BY o.createdAt DESC
            """)
    List<Opportunity> searchWithKeyword(
            @Param("today") LocalDate today,
            @Param("educationLevel") String educationLevel,
            @Param("country") String country,
            @Param("fieldOfStudy") String fieldOfStudy,
            @Param("provider") String provider,
            @Param("fundingType") String fundingType,
            @Param("deadlineBefore") LocalDate deadlineBefore,
            @Param("keyword") String keyword);

    @Query("""
            SELECT o FROM Opportunity o
            WHERE o.status = 'ACTIVE'
              AND (o.deadline IS NULL OR o.deadline >= :today)
            ORDER BY o.createdAt DESC
            """)
    List<Opportunity> findActiveFeatured(@Param("today") LocalDate today, Pageable pageable);

    @Query("""
            SELECT COUNT(o) FROM Opportunity o
            WHERE o.status = 'ACTIVE'
              AND (o.deadline IS NULL OR o.deadline >= :today)
            """)
    long countActive(@Param("today") LocalDate today);

    @Query("""
            SELECT COUNT(o) FROM Opportunity o
            WHERE o.status = 'ACTIVE'
              AND o.deadline IS NOT NULL
              AND o.deadline >= :today
            """)
    long countUpcomingDeadlines(@Param("today") LocalDate today);

    @Query("""
            SELECT o FROM Opportunity o
            WHERE LOWER(o.title) LIKE LOWER(CONCAT('%', :q, '%'))
               OR LOWER(o.providerName) LIKE LOWER(CONCAT('%', :q, '%'))
               OR LOWER(o.description) LIKE LOWER(CONCAT('%', :q, '%'))
               OR LOWER(o.country) LIKE LOWER(CONCAT('%', :q, '%'))
               OR LOWER(o.targetField) LIKE LOWER(CONCAT('%', :q, '%'))
            ORDER BY o.createdAt DESC
            """)
    List<Opportunity> adminSearchByKeyword(@Param("q") String query, Pageable pageable);

    @Query("""
            SELECT EXTRACT(YEAR FROM o.createdAt), EXTRACT(MONTH FROM o.createdAt), COUNT(o)
            FROM Opportunity o
            WHERE o.createdAt IS NOT NULL
            GROUP BY EXTRACT(YEAR FROM o.createdAt), EXTRACT(MONTH FROM o.createdAt)
            """)
    List<Object[]> countOpportunitiesGroupedByYearMonth();
}
