package com.scholarzim.service.impl;

import com.scholarzim.dto.PageResult;
import com.scholarzim.entity.AuditLog;
import com.scholarzim.repository.AuditLogRepository;
import com.scholarzim.service.AuditLogService;
import org.springframework.data.domain.Page;
import org.springframework.data.domain.PageRequest;
import org.springframework.data.domain.Sort;
import org.springframework.stereotype.Service;

@Service
public class AuditLogServiceImpl implements AuditLogService {

    private final AuditLogRepository auditLogRepository;

    public AuditLogServiceImpl(AuditLogRepository auditLogRepository) {
        this.auditLogRepository = auditLogRepository;
    }

    @Override
    public PageResult<AuditLog> search(String query, int page, int size) {

        PageRequest pageable = PageRequest.of(Math.max(page, 0), Math.max(size, 1),
                Sort.by(Sort.Direction.DESC, "createdAt"));

        Page<AuditLog> result;
        if (query == null || query.isBlank()) {
            result = auditLogRepository.findAllByOrderByCreatedAtDesc(pageable);
        } else {
            String q = query.trim();
            result = auditLogRepository
                    .findByActorEmailContainingIgnoreCaseOrActionContainingIgnoreCaseOrDetailsContainingIgnoreCase(
                            q, q, q, pageable);
        }

        return new PageResult<>(result.getContent(), result.getNumber(), result.getSize(),
                result.getTotalElements());
    }
}
