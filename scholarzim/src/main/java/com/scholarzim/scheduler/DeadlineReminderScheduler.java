package com.scholarzim.scheduler;

import com.scholarzim.entity.Application;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.User;
import com.scholarzim.repository.ApplicationRepository;
import com.scholarzim.repository.OpportunityRepository;
import com.scholarzim.service.NotificationService;
import com.scholarzim.service.SmsService;
import com.scholarzim.util.ApplicationStatus;
import com.scholarzim.util.NotificationType;
import lombok.extern.slf4j.Slf4j;
import org.springframework.scheduling.annotation.Scheduled;
import org.springframework.stereotype.Component;

import java.time.LocalDate;
import java.util.List;

@Slf4j
@Component
public class DeadlineReminderScheduler {

    private static final int REMINDER_WINDOW_DAYS = 3;

    private final OpportunityRepository opportunityRepository;
    private final ApplicationRepository applicationRepository;
    private final NotificationService notificationService;
    private final SmsService smsService;

    public DeadlineReminderScheduler(
            OpportunityRepository opportunityRepository,
            ApplicationRepository applicationRepository,
            NotificationService notificationService,
            SmsService smsService) {

        this.opportunityRepository = opportunityRepository;
        this.applicationRepository = applicationRepository;
        this.notificationService = notificationService;
        this.smsService = smsService;
    }

    /**
     * Runs every day at 08:00. Notifies applicants with a pending application to an
     * active opportunity whose deadline falls within the next {@value #REMINDER_WINDOW_DAYS} days.
     */
    @Scheduled(cron = "0 0 8 * * *")
    public void sendDeadlineReminders() {

        log.info("Running deadline reminder job");

        LocalDate today = LocalDate.now();
        LocalDate windowEnd = today.plusDays(REMINDER_WINDOW_DAYS);
        int remindersSent = 0;

        for (Opportunity opportunity : opportunityRepository.findAll()) {

            LocalDate deadline = opportunity.getDeadline();

            boolean closingSoon = deadline != null
                    && "ACTIVE".equalsIgnoreCase(opportunity.getStatus())
                    && !deadline.isBefore(today)
                    && !deadline.isAfter(windowEnd);

            if (!closingSoon) {
                continue;
            }

            List<Application> applications =
                    applicationRepository.findByOpportunity(opportunity);

            for (Application application : applications) {

                String status = application.getApplicationStatus();
                if (status == null || (!ApplicationStatus.SUBMITTED.equals(status)
                        && !ApplicationStatus.UNDER_REVIEW.equals(status)
                        && !ApplicationStatus.PENDING.equals(status))) {
                    continue;
                }

                User applicant = application.getUser();
                Long opportunityId = opportunity.getOpportunityId();

                if (notificationService.hasNotification(
                        applicant, NotificationType.DEADLINE_REMINDER, opportunityId)) {
                    continue;
                }

                notificationService.notifyUser(
                        applicant,
                        NotificationType.DEADLINE_REMINDER,
                        "Deadline approaching for \"" + opportunity.getTitle()
                                + "\" (closes " + deadline + ").",
                        "/my-applications",
                        opportunityId);
                smsService.sendDeadlineReminder(
                        applicant.getPhone(),
                        "ScholarZim: \"" + opportunity.getTitle() + "\" closes " + deadline + ".");
                remindersSent++;
            }
        }

        log.info("Deadline reminder job finished — {} reminders sent", remindersSent);
    }
}
