package com.scholarzim.repository;

import com.scholarzim.entity.AuditLog;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.util.List;


public interface AuditLogRepository extends JpaRepository<AuditLog, Long> {

    List<AuditLog> findTop50ByOrderByCreatedAtDesc();

    org.springframework.data.domain.Page<AuditLog> findAllByOrderByCreatedAtDesc(
            org.springframework.data.domain.Pageable pageable);

    org.springframework.data.domain.Page<AuditLog> findByActionOrderByCreatedAtDesc(
            String action, org.springframework.data.domain.Pageable pageable);

    org.springframework.data.domain.Page<AuditLog> findByActorEmailContainingIgnoreCaseOrActionContainingIgnoreCaseOrDetailsContainingIgnoreCase(
            String actorEmail, String action, String details,
            org.springframework.data.domain.Pageable pageable);

    @Query("""
            SELECT a FROM AuditLog a
            WHERE a.action = :action
            AND (
                LOWER(a.actorEmail) LIKE LOWER(CONCAT('%', :q, '%'))
                OR LOWER(a.action) LIKE LOWER(CONCAT('%', :q, '%'))
                OR LOWER(a.details) LIKE LOWER(CONCAT('%', :q, '%'))
            )
            ORDER BY a.createdAt DESC
            """)
    org.springframework.data.domain.Page<AuditLog> searchByActionAndKeyword(
            @Param("action") String action,
            @Param("q") String query,
            org.springframework.data.domain.Pageable pageable);

    @Query("SELECT DISTINCT a.action FROM AuditLog a ORDER BY a.action")
    List<String> findDistinctActions();

    @Query("""
            SELECT EXTRACT(YEAR FROM a.createdAt), EXTRACT(MONTH FROM a.createdAt), COUNT(a)
            FROM AuditLog a
            WHERE a.action = :action
              AND a.createdAt IS NOT NULL
              AND LOWER(a.details) LIKE LOWER(CONCAT('%', :detailKeyword, '%'))
            GROUP BY EXTRACT(YEAR FROM a.createdAt), EXTRACT(MONTH FROM a.createdAt)
            """)
    List<Object[]> countByActionGroupedByYearMonth(
            @Param("action") String action,
            @Param("detailKeyword") String detailKeyword);

    List<AuditLog> findTop20ByActionOrderByCreatedAtDesc(String action);
}
