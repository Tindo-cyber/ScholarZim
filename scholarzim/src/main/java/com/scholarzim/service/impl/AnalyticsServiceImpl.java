package com.scholarzim.service.impl;

import com.scholarzim.dto.AdminDashboardDTO;
import com.scholarzim.dto.AuditActivityDTO;
import com.scholarzim.dto.ChartData;
import com.scholarzim.repository.ApplicantProfileRepository;
import com.scholarzim.repository.ApplicationRepository;
import com.scholarzim.repository.AuditLogRepository;
import com.scholarzim.repository.OpportunityRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.AnalyticsService;
import com.scholarzim.util.AccountStatus;
import com.scholarzim.util.ApplicationStatus;
import com.scholarzim.util.OpportunityStatus;
import com.scholarzim.util.RoleNames;
import org.springframework.stereotype.Service;

import java.time.YearMonth;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;
import java.util.stream.Collectors;


@Service
public class AnalyticsServiceImpl implements AnalyticsService {

    private final UserRepository userRepository;
    private final OpportunityRepository opportunityRepository;
    private final ApplicationRepository applicationRepository;
    private final ApplicantProfileRepository profileRepository;
    private final AuditLogRepository auditLogRepository;

    public AnalyticsServiceImpl(
            UserRepository userRepository,
            OpportunityRepository opportunityRepository,
            ApplicationRepository applicationRepository,
            ApplicantProfileRepository profileRepository,
            AuditLogRepository auditLogRepository) {

        this.userRepository = userRepository;
        this.opportunityRepository = opportunityRepository;
        this.applicationRepository = applicationRepository;
        this.profileRepository = profileRepository;
        this.auditLogRepository = auditLogRepository;
    }

    @Override
    public AdminDashboardDTO getDashboardStats() {

        AdminDashboardDTO dto = new AdminDashboardDTO();

        dto.setTotalUsers(userRepository.count());
        dto.setTotalApplicants(userRepository.countByRoleRoleName(RoleNames.APPLICANT));
        dto.setTotalProviders(userRepository.countByRoleRoleName(RoleNames.PROVIDER));
        dto.setTotalOpportunities(opportunityRepository.count());
        dto.setTotalApplications(applicationRepository.count());
        dto.setApprovedApplications(
                applicationRepository.countByApplicationStatus(ApplicationStatus.APPROVED));
        dto.setRejectedApplications(
                applicationRepository.countByApplicationStatus(ApplicationStatus.REJECTED));
        dto.setPendingApplications(
                applicationRepository.countByApplicationStatus(ApplicationStatus.PENDING));
        dto.setActiveOpportunities(opportunityRepository.countByStatus(OpportunityStatus.ACTIVE));
        dto.setClosedOpportunities(
                Math.max(0, dto.getTotalOpportunities() - dto.getActiveOpportunities()));
        dto.setActiveUsers(userRepository.countByAccountStatus(AccountStatus.ACTIVE));
        dto.setDistinctInstitutions(profileRepository.countDistinctInstitutions());

        long decided = dto.getApprovedApplications() + dto.getRejectedApplications();
        dto.setApprovalRate(decided == 0 ? 0
                : (int) Math.round(100.0 * dto.getApprovedApplications() / decided));

        dto.setCompleteProfiles(profileRepository.count());

        return dto;
    }

    @Override
    public List<AuditActivityDTO> getRecentActivity(int limit) {

        return auditLogRepository.findTop50ByOrderByCreatedAtDesc().stream()
                .limit(limit)
                .map(log -> {
                    AuditActivityDTO dto = new AuditActivityDTO();
                    dto.setActorEmail(log.getActorEmail());
                    dto.setAction(log.getAction());
                    dto.setDetails(log.getDetails());
                    dto.setCreatedAt(log.getCreatedAt());
                    return dto;
                })
                .toList();
    }

    @Override
    public List<Long> getMonthlyApplicationCounts(int months) {

        Map<YearMonth, Long> counts = applicationRepository.countApplicationsGroupedByYearMonth()
                .stream()
                .collect(Collectors.toMap(
                        row -> YearMonth.of(((Number) row[0]).intValue(), ((Number) row[1]).intValue()),
                        row -> ((Number) row[2]).longValue(),
                        Long::sum));

        List<Long> result = new ArrayList<>(months);
        YearMonth now = YearMonth.now();

        for (int i = months - 1; i >= 0; i--) {
            YearMonth ym = now.minusMonths(i);
            result.add(counts.getOrDefault(ym, 0L));
        }

        return result;
    }

    @Override
    public ChartData getTopProviders(int limit) {
        return toChartData(applicationRepository.countApplicationsByProvider(), limit);
    }

    @Override
    public ChartData getMostAppliedOpportunities(int limit) {
        return toChartData(applicationRepository.countApplicationsByOpportunity(), limit);
    }

    @Override
    public ChartData getApplicationStatusBreakdown() {
        ChartData chart = new ChartData();
        applicationRepository.countApplicationsGroupedByStatus().forEach(row -> {
            String status = (String) row[0];
            chart.add(ApplicationStatus.displayLabel(status), ((Number) row[1]).longValue());
        });
        return chart;
    }

    @Override
    public ChartData getUserRoleBreakdown() {
        ChartData chart = new ChartData();
        chart.add("Students", userRepository.countByRoleRoleName(RoleNames.APPLICANT));
        chart.add("Providers", userRepository.countByRoleRoleName(RoleNames.PROVIDER));
        chart.add("Admins", userRepository.countByRoleRoleName(RoleNames.ADMIN));
        return chart;
    }

    @Override
    public ChartData getScholarshipAvailabilityBreakdown() {
        long active = opportunityRepository.countByStatus(OpportunityStatus.ACTIVE);
        long total = opportunityRepository.count();
        ChartData chart = new ChartData();
        chart.add("Open", active);
        chart.add("Closed / inactive", Math.max(0, total - active));
        return chart;
    }

    private ChartData toChartData(List<Object[]> rows, int limit) {

        ChartData chart = new ChartData();

        rows.stream()
                .limit(limit)
                .forEach(row -> chart.add(
                        (String) row[0],
                        ((Number) row[1]).longValue()));

        return chart;
    }
}
