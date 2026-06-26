package com.scholarzim.repository;

import com.scholarzim.entity.AuditLog;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.List;

public interface AuditLogRepository extends JpaRepository<AuditLog, Long> {

    List<AuditLog> findTop50ByOrderByCreatedAtDesc();

    org.springframework.data.domain.Page<AuditLog> findAllByOrderByCreatedAtDesc(
            org.springframework.data.domain.Pageable pageable);

    org.springframework.data.domain.Page<AuditLog> findByActorEmailContainingIgnoreCaseOrActionContainingIgnoreCaseOrDetailsContainingIgnoreCase(
            String actorEmail, String action, String details,
            org.springframework.data.domain.Pageable pageable);
}
