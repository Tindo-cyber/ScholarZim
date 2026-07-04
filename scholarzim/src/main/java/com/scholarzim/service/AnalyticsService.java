package com.scholarzim.service;

import com.scholarzim.dto.AdminDashboardDTO;
import com.scholarzim.dto.AuditActivityDTO;
import com.scholarzim.dto.ChartData;

import java.util.List;


public interface AnalyticsService {

    AdminDashboardDTO getDashboardStats();

    List<AuditActivityDTO> getRecentActivity(int limit);

    /**
     * Returns the number of applications for each of the last {@code months} months,
     * ordered from oldest to newest.
     */
    List<Long> getMonthlyApplicationCounts(int months);

    /**
     * Top providers ranked by number of applications received, limited to {@code limit}.
     */
    ChartData getTopProviders(int limit);

    /**
     * Opportunities ranked by number of applications received, limited to {@code limit}.
     */
    ChartData getMostAppliedOpportunities(int limit);
}
