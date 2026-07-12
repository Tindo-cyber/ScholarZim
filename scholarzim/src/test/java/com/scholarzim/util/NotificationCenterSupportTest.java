package com.scholarzim.util;

import com.scholarzim.entity.Notification;
import org.junit.jupiter.api.Test;

import java.time.LocalDateTime;
import java.util.ArrayList;
import java.util.List;

import static org.assertj.core.api.Assertions.assertThat;


class NotificationCenterSupportTest {

    @Test
    void mapsNotificationTypesToProfessionalCategories() {
        assertThat(NotificationCenterSupport.category(NotificationType.APPLICATION_APPROVED))
                .isEqualTo("APPLICATIONS");
        assertThat(NotificationCenterSupport.category(NotificationType.NEW_OPPORTUNITY))
                .isEqualTo("SCHOLARSHIPS");
        assertThat(NotificationCenterSupport.category(NotificationType.MESSAGE_RECEIVED))
                .isEqualTo("MESSAGES");
        assertThat(NotificationCenterSupport.category(NotificationType.PROFILE_INCOMPLETE))
                .isEqualTo("SYSTEM");
    }

    @Test
    void filtersByCategorySearchAndReadStatus() {
        Notification application = notification(
                NotificationType.APPLICATION_APPROVED, "Your application was awarded", false);
        Notification message = notification(
                NotificationType.MESSAGE_RECEIVED, "New reply from the university", false);

        var result = NotificationCenterSupport.buildPage(
                List.of(application, message), "university", "MESSAGES", "UNREAD", 0);

        assertThat(result.filteredTotal()).isEqualTo(1);
        assertThat(result.filteredUnread()).isEqualTo(1);
        assertThat(result.notifications()).containsExactly(message);
        assertThat(result.categoryCounts().get("APPLICATIONS")).isEqualTo(1);
        assertThat(result.categoryCounts().get("MESSAGES")).isEqualTo(1);
    }

    @Test
    void paginatesTenNotificationsAtATime() {
        List<Notification> notifications = new ArrayList<>();
        for (int index = 0; index < 12; index++) {
            notifications.add(notification(
                    NotificationType.NEW_OPPORTUNITY, "Scholarship " + index, true));
        }

        var result = NotificationCenterSupport.buildPage(
                notifications, "", "SCHOLARSHIPS", "ALL", 1);

        assertThat(result.totalPages()).isEqualTo(2);
        assertThat(result.currentPage()).isEqualTo(1);
        assertThat(result.notifications()).hasSize(2);
    }

    private static Notification notification(String type, String message, boolean read) {
        Notification notification = new Notification();
        notification.setType(type);
        notification.setMessage(message);
        notification.setRead(read);
        notification.setCreatedAt(LocalDateTime.now());
        return notification;
    }
}
