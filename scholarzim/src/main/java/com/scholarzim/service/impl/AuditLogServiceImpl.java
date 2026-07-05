package com.scholarzim.service.impl;

import com.scholarzim.dto.PageResult;
import com.scholarzim.entity.AuditLog;
import com.scholarzim.repository.AuditLogRepository;
import com.scholarzim.service.AuditLogService;
import org.springframework.data.domain.Page;
import org.springframework.data.domain.PageRequest;
import org.springframework.data.domain.Sort;
import org.springframework.stereotype.Service;

import java.util.List;


@Service
public class AuditLogServiceImpl implements AuditLogService {

    private final AuditLogRepository auditLogRepository;

    public AuditLogServiceImpl(AuditLogRepository auditLogRepository) {
        this.auditLogRepository = auditLogRepository;
    }

    @Override
    public PageResult<AuditLog> search(String query, String action, int page, int size) {

        PageRequest pageable = PageRequest.of(Math.max(page, 0), Math.max(size, 1),
                Sort.by(Sort.Direction.DESC, "createdAt"));

        String q = query != null ? query.trim() : "";
        String act = action != null ? action.trim() : "";
        boolean hasQuery = !q.isBlank();
        boolean hasAction = !act.isBlank();

        Page<AuditLog> result;
        if (!hasQuery && !hasAction) {
            result = auditLogRepository.findAllByOrderByCreatedAtDesc(pageable);
        } else if (hasAction && !hasQuery) {
            result = auditLogRepository.findByActionOrderByCreatedAtDesc(act, pageable);
        } else if (!hasAction) {
            result = auditLogRepository
                    .findByActorEmailContainingIgnoreCaseOrActionContainingIgnoreCaseOrDetailsContainingIgnoreCase(
                            q, q, q, pageable);
        } else {
            result = auditLogRepository.searchByActionAndKeyword(act, q, pageable);
        }

        return new PageResult<>(result.getContent(), result.getNumber(), result.getSize(),
                result.getTotalElements());
    }

    @Override
    public List<String> listDistinctActions() {
        return auditLogRepository.findDistinctActions();
    }
}
