package com.scholarzim.controller;

import com.scholarzim.service.NotificationService;
import lombok.extern.slf4j.Slf4j;
import org.springframework.lang.NonNull;
import org.springframework.security.core.Authentication;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.GetMapping;

import java.util.List;


@Slf4j
@Controller
public class MessagesController {

    private final NotificationService notificationService;

    public MessagesController(NotificationService notificationService) {
        this.notificationService = notificationService;
    }

    @GetMapping("/messages")
    public String inbox(@NonNull Authentication authentication, Model model) {

        String email = authentication.getName();
        try {
            model.addAttribute("messages", notificationService.allForUser(email));
            model.addAttribute("unreadCount", notificationService.unreadCount(email));
        } catch (Exception ex) {
            log.warn("Messages inbox failed for {}: {}", email, ex.getMessage());
            model.addAttribute("messages", List.of());
            model.addAttribute("unreadCount", 0L);
            model.addAttribute("loadFailed", true);
        }
        return "messages/inbox";
    }
}
