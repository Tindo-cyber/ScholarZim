package com.scholarzim.service.impl;

import com.scholarzim.entity.AuditLog;
import com.scholarzim.repository.AuditLogRepository;
import com.scholarzim.service.AuditService;
import lombok.extern.slf4j.Slf4j;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Propagation;
import org.springframework.transaction.annotation.Transactional;

import java.time.LocalDateTime;


@Slf4j
@Service
public class AuditServiceImpl implements AuditService {

    private final AuditLogRepository auditLogRepository;

    public AuditServiceImpl(AuditLogRepository auditLogRepository) {
        this.auditLogRepository = auditLogRepository;
    }

    @Override
    @Transactional(propagation = Propagation.REQUIRES_NEW)
    public void log(
            String actorEmail,
            String action,
            String entityType,
            Long entityId,
            String details) {

        AuditLog entry = new AuditLog();
        entry.setActorEmail(actorEmail);
        entry.setAction(action);
        entry.setEntityType(entityType);
        entry.setEntityId(entityId);
        entry.setDetails(details);
        entry.setCreatedAt(LocalDateTime.now());

        try {
            auditLogRepository.save(entry);
            log.info("AUDIT {} {} id={} by={} — {}", action, entityType, entityId, actorEmail, details);
        } catch (Exception ex) {
            // Auditing must not turn into a user-facing 500 (DB blips, schema drift, etc.).
            log.warn("Failed to persist audit log {} {} id={} by={}: {}",
                    action, entityType, entityId, actorEmail, ex.getMessage());
        }
    }
}
