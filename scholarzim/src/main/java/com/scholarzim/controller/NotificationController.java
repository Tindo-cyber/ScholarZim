package com.scholarzim.controller;

import com.scholarzim.service.NotificationService;
import org.springframework.security.core.Authentication;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestHeader;

@Controller
public class NotificationController {

    private final NotificationService notificationService;

    public NotificationController(NotificationService notificationService) {
        this.notificationService = notificationService;
    }

    @GetMapping("/notifications")
    public String list(Authentication authentication, Model model) {

        model.addAttribute(
                "notifications",
                notificationService.allForUser(authentication.getName()));

        return "notifications/list";
    }

    @GetMapping("/notifications/{id}/open")
    public String open(
            @PathVariable Long id,
            Authentication authentication) {

        String link = notificationService.open(id, authentication.getName());
        return "redirect:" + link;
    }

    @PostMapping("/notifications/read-all")
    public String markAllRead(
            Authentication authentication,
            @RequestHeader(value = "Referer", required = false) String referer) {

        notificationService.markAllRead(authentication.getName());

        if (referer != null && !referer.isBlank()) {
            return "redirect:" + referer;
        }
        return "redirect:/notifications";
    }
}
