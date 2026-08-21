package com.scholarzim.scheduler;

import com.scholarzim.entity.Application;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.SavedScholarship;
import com.scholarzim.entity.User;
import com.scholarzim.repository.ApplicationRepository;
import com.scholarzim.repository.OpportunityRepository;
import com.scholarzim.repository.SavedScholarshipRepository;
import com.scholarzim.service.NotificationService;
import com.scholarzim.service.SmsService;
import com.scholarzim.util.ApplicationStatus;
import com.scholarzim.util.NotificationType;
import com.scholarzim.util.OpportunityStatus;
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
    private final SavedScholarshipRepository savedScholarshipRepository;
    private final NotificationService notificationService;
    private final SmsService smsService;

    public DeadlineReminderScheduler(
            OpportunityRepository opportunityRepository,
            ApplicationRepository applicationRepository,
            SavedScholarshipRepository savedScholarshipRepository,
            NotificationService notificationService,
            SmsService smsService) {

        this.opportunityRepository = opportunityRepository;
        this.applicationRepository = applicationRepository;
        this.savedScholarshipRepository = savedScholarshipRepository;
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

        List<Opportunity> closingSoon = opportunityRepository.findByStatusAndDeadlineBetween(
                OpportunityStatus.ACTIVE, today, windowEnd);

        for (Opportunity opportunity : closingSoon) {
            LocalDate deadline = opportunity.getDeadline();
            Long opportunityId = opportunity.getOpportunityId();
            remindersSent += remindPendingApplicants(opportunity, deadline, opportunityId);
            remindersSent += remindSavedNotApplied(opportunity, deadline, opportunityId);
        }

        log.info("Deadline reminder job finished — {} reminders sent", remindersSent);
    }

    private int remindPendingApplicants(Opportunity opportunity, LocalDate deadline, Long opportunityId) {

        int sent = 0;
        List<Application> applications = applicationRepository.findByOpportunity(opportunity);

        for (Application application : applications) {

            if (!isPendingForReminder(application.getApplicationStatus())) {
                continue;
            }

            User applicant = application.getUser();
            if (applicant == null) {
                continue;
            }

            if (sendReminderIfNeeded(
                    applicant,
                    opportunity,
                    opportunityId,
                    "Deadline approaching for \"" + opportunity.getTitle()
                            + "\" (closes " + deadline + ").",
                    "/my-applications",
                    "ScholarZim: \"" + opportunity.getTitle() + "\" closes " + deadline + ".")) {
                sent++;
            }
        }
        return sent;
    }

    private int remindSavedNotApplied(Opportunity opportunity, LocalDate deadline, Long opportunityId) {

        int sent = 0;

        for (SavedScholarship saved : savedScholarshipRepository.findByOpportunityOpportunityId(opportunityId)) {

            User applicant = saved.getUser();
            if (applicant == null) {
                continue;
            }

            if (applicationRepository.existsByUserAndOpportunity(applicant, opportunity)) {
                continue;
            }

            if (sendReminderIfNeeded(
                    applicant,
                    opportunity,
                    opportunityId,
                    "Saved scholarship \"" + opportunity.getTitle()
                            + "\" closes " + deadline + ". Apply before the deadline.",
                    "/apply/" + opportunityId,
                    "ScholarZim: saved \"" + opportunity.getTitle() + "\" closes " + deadline + ".")) {
                sent++;
            }
        }
        return sent;
    }

    private static boolean isPendingForReminder(String status) {
        return status != null && (ApplicationStatus.SUBMITTED.equals(status)
                || ApplicationStatus.UNDER_REVIEW.equals(status)
                || ApplicationStatus.PENDING.equals(status));
    }

    private boolean sendReminderIfNeeded(
            User applicant,
            Opportunity opportunity,
            Long opportunityId,
            String notificationMessage,
            String link,
            String smsMessage) {

        if (notificationService.hasNotification(
                applicant, NotificationType.DEADLINE_REMINDER, opportunityId)) {
            return false;
        }

        notificationService.notifyUser(
                applicant,
                NotificationType.DEADLINE_REMINDER,
                notificationMessage,
                link,
                opportunityId);
        smsService.sendDeadlineReminder(applicant.getPhone(), smsMessage);
        return true;
    }
}
