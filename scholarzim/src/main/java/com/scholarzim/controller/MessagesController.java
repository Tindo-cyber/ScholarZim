package com.scholarzim.controller;

import com.scholarzim.service.NotificationService;
import org.springframework.lang.NonNull;
import org.springframework.security.core.Authentication;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.GetMapping;


@Controller
public class MessagesController {

    private final NotificationService notificationService;

    public MessagesController(NotificationService notificationService) {
        this.notificationService = notificationService;
    }

    @GetMapping("/messages")
    public String inbox(@NonNull Authentication authentication, Model model) {

        model.addAttribute("messages", notificationService.allForUser(authentication.getName()));
        model.addAttribute("unreadCount", notificationService.unreadCount(authentication.getName()));
        return "messages/inbox";
    }
}
