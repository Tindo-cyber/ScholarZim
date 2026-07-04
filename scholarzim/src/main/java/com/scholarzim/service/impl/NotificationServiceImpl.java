package com.scholarzim.service.impl;

import com.scholarzim.entity.Notification;
import com.scholarzim.entity.User;
import com.scholarzim.repository.NotificationRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.EmailService;
import com.scholarzim.service.NotificationService;
import org.springframework.lang.NonNull;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.LocalDateTime;
import java.util.List;


@Service
public class NotificationServiceImpl implements NotificationService {

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

        if (recipient.getEmail() != null && type != null
                && (type.contains("STATUS") || type.contains("DEADLINE"))) {
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
        User user = userRepository.findByEmail(email).orElse(null);
        if (user == null) {
            return List.of();
        }
        return notificationRepository.findByUserOrderByCreatedAtDesc(user);
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
