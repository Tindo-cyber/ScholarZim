package com.scholarzim.dto;

import lombok.Getter;
import lombok.Setter;


@Getter
@Setter
public class ApplicantDashboardDTO {

    private int profileCompletion;
    private boolean hasProfile;
    private long applicationsSubmitted;
    private long pendingApplications;
    private long approvedApplications;
    private long rejectedApplications;
    private long savedCount;
    private boolean hasResultsCertificate;
}
