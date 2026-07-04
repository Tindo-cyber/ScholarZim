package com.scholarzim.controller;

import com.scholarzim.service.NotificationService;
import lombok.extern.slf4j.Slf4j;
import org.springframework.lang.Nullable;
import org.springframework.security.authentication.AnonymousAuthenticationToken;
import org.springframework.security.core.Authentication;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.ControllerAdvice;
import org.springframework.web.bind.annotation.ModelAttribute;


@Slf4j
@ControllerAdvice
public class NotificationModelAdvice {

    private final NotificationService notificationService;

    public NotificationModelAdvice(NotificationService notificationService) {
        this.notificationService = notificationService;
    }

    @ModelAttribute
    public void populateNotifications(Model model, @Nullable Authentication authentication) {

        if (authentication == null
                || !authentication.isAuthenticated()
                || authentication instanceof AnonymousAuthenticationToken) {
            return;
        }

        try {
            String email = authentication.getName();
            model.addAttribute("notifUnreadCount", notificationService.unreadCount(email));
            model.addAttribute("notifRecent", notificationService.recentForUser(email));
        } catch (Exception ex) {
            log.warn("Failed to load notifications for session: {}", ex.getMessage());
        }
    }
}
