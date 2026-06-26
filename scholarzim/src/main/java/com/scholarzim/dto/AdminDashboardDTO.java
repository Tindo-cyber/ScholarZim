package com.scholarzim.dto;

import lombok.Getter;
import lombok.Setter;

@Getter
@Setter
public class AdminDashboardDTO {

    private long totalUsers;
    private long totalApplicants;
    private long totalProviders;
    private long totalOpportunities;
    private long totalApplications;
    private long approvedApplications;
    private long rejectedApplications;
    private long pendingApplications;
    private long activeOpportunities;
    private int approvalRate;
    private long completeProfiles;
}
