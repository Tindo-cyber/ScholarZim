package com.scholarzim.service;

import com.scholarzim.dto.PageResult;
import com.scholarzim.entity.AuditLog;

public interface AuditLogService {

    PageResult<AuditLog> search(String query, int page, int size);
}
