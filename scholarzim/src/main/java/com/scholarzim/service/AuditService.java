package com.scholarzim.service;

public interface AuditService {

    void log(String actorEmail, String action, String entityType, Long entityId, String details);
}
