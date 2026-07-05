package com.scholarzim.service;

import com.scholarzim.dto.PageResult;
import com.scholarzim.entity.AuditLog;

import java.util.List;


public interface AuditLogService {

    PageResult<AuditLog> search(String query, String action, int page, int size);

    List<String> listDistinctActions();
}
