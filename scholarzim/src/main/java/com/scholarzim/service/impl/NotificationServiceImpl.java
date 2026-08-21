package com.scholarzim.service.impl;

import com.scholarzim.entity.Notification;
import com.scholarzim.entity.User;
import com.scholarzim.repository.NotificationRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.EmailService;
import com.scholarzim.service.NotificationService;
import com.scholarzim.util.NotificationCenterSupport;
import com.scholarzim.util.NotificationType;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.lang.NonNull;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;
import org.springframework.util.StringUtils;

import java.time.LocalDateTime;
import java.util.List;
import java.util.Set;


@Service
public class NotificationServiceImpl implements NotificationService {

    // APPLICATION_APPROVED/REJECTED are deliberately excluded: ApplicationServiceImpl
    // already sends a detailed, purpose-written email for those two decisions, so
    // including them here would fire this generic "ScholarZim update" email as a
    // second, redundant message for the same event.
    private static final Set<String> EMAIL_TYPES = Set.of(
            NotificationType.APPLICATION_SUBMITTED,
            NotificationType.DOCUMENTS_REQUESTED,
            NotificationType.DEADLINE_REMINDER,
            NotificationType.PROFILE_INCOMPLETE);

    private final NotificationRepository notificationRepository;
    private final UserRepository userRepository;
    private final EmailService emailService;
    private final String baseUrl;

    public NotificationServiceImpl(
            NotificationRepository notificationRepository,
            UserRepository userRepository,
            EmailService emailService,
            @Value("${scholarzim.app.base-url:http://localhost:8080}") String baseUrl) {

        this.notificationRepository = notificationRepository;
        this.userRepository = userRepository;
        this.emailService = emailService;
        this.baseUrl = baseUrl;
    }

    @Override
    public void notifyUser(User recipient, String type, String message,
                           String link, Long relatedId) {

        if (recipient == null) {
            return;
        }

        Notification notification = new Notification();
        notification.setUser(recipient);
        notification.setType(type);
        notification.setMessage(message);
        notification.setLink(link);
        notification.setRelatedId(relatedId);
        notification.setRead(false);
        notification.setCreatedAt(LocalDateTime.now());

        notificationRepository.save(notification);

        if (recipient.getEmail() != null && type != null && EMAIL_TYPES.contains(type)
                && NotificationCenterSupport.emailAllowedForType(recipient, type)) {
            // "message" alone gives no way to act on the notification — link was already
            // captured for the in-app bell (notification.setLink above) but was never
            // carried into the email body, so append it as an absolute URL here too.
            String body = StringUtils.hasText(link)
                    ? message + "\n\n" + baseUrl + link
                    : message;
            emailService.sendStatusUpdateEmail(
                    recipient.getEmail(),
                    "ScholarZim update",
                    body);
        }
    }

    @Override
    public boolean hasNotification(User recipient, String type, Long relatedId) {
        if (recipient == null || relatedId == null) {
            return false;
        }
        return notificationRepository.existsByUserAndTypeAndRelatedId(recipient, type, relatedId);
    }

    @Override
    public List<Notification> recentForUser(String email) {
        User user = userRepository.findByEmail(email).orElse(null);
        if (user == null) {
            return List.of();
        }
        return notificationRepository.findTop10ByUserOrderByCreatedAtDesc(user);
    }

    @Override
    @Transactional(readOnly = true)
    public List<Notification> allForUser(String email) {
        return allForUser(email, null);
    }

    @Override
    @Transactional(readOnly = true)
    public List<Notification> allForUser(String email, String typeFilter) {
        User user = userRepository.findByEmail(email).orElse(null);
        if (user == null) {
            return List.of();
        }
        if (StringUtils.hasText(typeFilter)) {
            return notificationRepository.findByUserAndTypeOrderByCreatedAtDesc(
                    user, typeFilter.trim());
        }
        return notificationRepository.findByUserOrderByCreatedAtDesc(user);
    }

    @Override
    public List<String> listTypesForUser(String email) {
        User user = userRepository.findByEmail(email).orElse(null);
        if (user == null) {
            return List.of();
        }
        return notificationRepository.findDistinctTypesByUser(user);
    }

    @Override
    public long unreadCount(String email) {
        User user = userRepository.findByEmail(email).orElse(null);
        if (user == null) {
            return 0;
        }
        return notificationRepository.countByUserAndReadFalse(user);
    }

    @Override
    public NotificationNavData navDataForUser(String email) {
        User user = userRepository.findByEmail(email).orElse(null);
        if (user == null) {
            return NotificationNavData.empty();
        }
        return new NotificationNavData(
                notificationRepository.countByUserAndReadFalse(user),
                notificationRepository.findTop10ByUserOrderByCreatedAtDesc(user));
    }

    @Override
    @Transactional
    public void markAllRead(String email) {
        User user = userRepository.findByEmail(email).orElse(null);
        if (user == null) {
            return;
        }
        notificationRepository.markAllReadForUser(user);
    }

    @Override
    @Transactional
    public String open(@NonNull Long notificationId, String email) {

        Notification notification =
                notificationRepository.findById(notificationId).orElse(null);

        if (notification == null
                || notification.getUser() == null
                || notification.getUser().getEmail() == null
                || !notification.getUser().getEmail().equalsIgnoreCase(email)) {
            return "/dashboard";
        }

        if (!notification.isRead()) {
            notification.setRead(true);
            notificationRepository.save(notification);
        }

        String link = notification.getLink();
        return (link != null && !link.isBlank()) ? link : "/dashboard";
    }
}
