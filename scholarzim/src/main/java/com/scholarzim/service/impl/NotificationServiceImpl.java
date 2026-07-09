package com.scholarzim.service.impl;

import com.scholarzim.entity.Notification;
import com.scholarzim.entity.User;
import com.scholarzim.repository.NotificationRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.EmailService;
import com.scholarzim.service.NotificationService;
import com.scholarzim.util.NotificationType;
import org.springframework.lang.NonNull;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;
import org.springframework.util.StringUtils;

import java.time.LocalDateTime;
import java.util.List;
import java.util.Set;


@Service
public class NotificationServiceImpl implements NotificationService {

    private static final Set<String> EMAIL_TYPES = Set.of(
            NotificationType.APPLICATION_APPROVED,
            NotificationType.APPLICATION_REJECTED,
            NotificationType.APPLICATION_SUBMITTED,
            NotificationType.DOCUMENTS_REQUESTED,
            NotificationType.DEADLINE_REMINDER,
            NotificationType.PROFILE_INCOMPLETE);

    private final NotificationRepository notificationRepository;
    private final UserRepository userRepository;
    private final EmailService emailService;

    public NotificationServiceImpl(
            NotificationRepository notificationRepository,
            UserRepository userRepository,
            EmailService emailService) {

        this.notificationRepository = notificationRepository;
        this.userRepository = userRepository;
        this.emailService = emailService;
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

        if (recipient.getEmail() != null && type != null && EMAIL_TYPES.contains(type)) {
            emailService.sendStatusUpdateEmail(
                    recipient.getEmail(),
                    "ScholarZim update",
                    message);
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
    public List<Notification> allForUser(String email) {
        return allForUser(email, null);
    }

    @Override
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
                || !email.equals(notification.getUser().getEmail())) {
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
