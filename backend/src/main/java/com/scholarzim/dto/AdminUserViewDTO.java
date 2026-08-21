package com.scholarzim.dto;

import lombok.Getter;
import lombok.Setter;


@Getter
@Setter
public class AdminUserViewDTO {

    private Long userId;
    private String fullName;
    private String email;
    private String phone;
    private String accountStatus;
    private String roleName;
    private String detail;
    private long applicationCount;
    private long opportunityCount;
    private String organisationType;
    private String registrationNumber;
    private java.time.LocalDateTime submittedAt;
    private boolean hasCertificate;
}
