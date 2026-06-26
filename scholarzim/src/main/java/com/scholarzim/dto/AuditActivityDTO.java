package com.scholarzim.dto;

import lombok.Getter;
import lombok.Setter;

import java.time.LocalDateTime;

@Getter
@Setter
public class AuditActivityDTO {

    private String actorEmail;
    private String action;
    private String details;
    private LocalDateTime createdAt;
}
