package com.scholarzim.scheduler;

import com.scholarzim.dto.ApplicantDashboardDTO;
import com.scholarzim.entity.User;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.ApplicantDashboardService;
import com.scholarzim.service.NotificationService;
import com.scholarzim.util.NotificationType;
import lombok.extern.slf4j.Slf4j;
import org.springframework.scheduling.annotation.Scheduled;
import org.springframework.stereotype.Component;


@Slf4j
@Component
public class ProfileReminderScheduler {

    private final UserRepository userRepository;
    private final ApplicantDashboardService applicantDashboardService;
    private final NotificationService notificationService;

    public ProfileReminderScheduler(
            UserRepository userRepository,
            ApplicantDashboardService applicantDashboardService,
            NotificationService notificationService) {

        this.userRepository = userRepository;
        this.applicantDashboardService = applicantDashboardService;
        this.notificationService = notificationService;
    }

    /**
     * Runs every day at 09:00. Reminds active applicants whose profile or results
     * certificate is incomplete (once per user until they complete their profile).
     */
    @Scheduled(cron = "0 0 9 * * *")
    public void sendProfileReminders() {

        log.info("Running profile reminder job");

        int remindersSent = 0;

        for (User applicant : userRepository.findByRoleRoleNameAndAccountStatus("ROLE_APPLICANT", "ACTIVE")) {

            if (applicant.getEmail() == null) {
                continue;
            }

            ApplicantDashboardDTO stats = applicantDashboardService.getDashboardStats(applicant.getEmail());
            if (stats.getProfileCompletion() >= 100 && stats.isHasResultsCertificate()) {
                continue;
            }

            Long userId = applicant.getUserId();
            if (notificationService.hasNotification(applicant, NotificationType.PROFILE_INCOMPLETE, userId)) {
                continue;
            }

            String message = stats.isHasResultsCertificate()
                    ? "Complete your academic profile to unlock better scholarship matches."
                    : "Upload your results certificate and finish your profile before applying.";

            notificationService.notifyUser(
                    applicant,
                    NotificationType.PROFILE_INCOMPLETE,
                    message,
                    "/applicant/profile",
                    userId);
            remindersSent++;
        }

        log.info("Profile reminder job finished — {} reminders sent", remindersSent);
    }
}
