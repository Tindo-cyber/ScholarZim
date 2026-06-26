package com.scholarzim.dto;

import lombok.Getter;
import lombok.Setter;

@Getter
@Setter
public class ProviderDashboardDTO {

    private long totalOpportunities;
    private long activeOpportunities;
    private long applicationsReceived;
    private long approvedApplications;
    private long rejectedApplications;
    private long pendingApplications;
}
