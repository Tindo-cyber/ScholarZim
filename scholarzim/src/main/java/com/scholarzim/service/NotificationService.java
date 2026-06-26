package com.scholarzim.service;

import com.scholarzim.entity.Notification;
import com.scholarzim.entity.User;
import org.springframework.lang.NonNull;

import java.util.List;

public interface NotificationService {

    void notifyUser(User recipient, String type, String message, String link, Long relatedId);

    boolean hasNotification(User recipient, String type, Long relatedId);

    List<Notification> recentForUser(String email);

    List<Notification> allForUser(String email);

    long unreadCount(String email);

    void markAllRead(String email);

    String open(@NonNull Long notificationId, String email);
}
