package com.scholarzim.controller;

import com.scholarzim.service.NotificationService;
import com.scholarzim.util.NotificationCenterSupport;
import lombok.extern.slf4j.Slf4j;
import org.springframework.lang.NonNull;
import org.springframework.security.core.Authentication;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestHeader;
import org.springframework.web.bind.annotation.RequestParam;

import java.util.Collections;
import java.util.List;


@Slf4j
@Controller
public class NotificationController {

    private final NotificationService notificationService;

    public NotificationController(NotificationService notificationService) {
        this.notificationService = notificationService;
    }

    @GetMapping("/notifications")
    public String list(
            @RequestParam(required = false) String q,
            @RequestParam(required = false) String category,
            @RequestParam(required = false, defaultValue = "ALL") String read,
            @RequestParam(defaultValue = "0") int page,
            @NonNull Authentication authentication,
            Model model) {

        String email = authentication.getName();
        model.addAttribute("q", q != null ? q : "");
        model.addAttribute("categoryFilter", category != null ? category : "");
        model.addAttribute("readFilter", read != null ? read : "ALL");
        model.addAttribute("categories", NotificationCenterSupport.CATEGORIES);

        try {
            var result = NotificationCenterSupport.buildPage(
                    notificationService.allForUser(email), q, category, read, page);

            model.addAttribute("notifications", result.notifications());
            model.addAttribute("categoryCounts", result.categoryCounts());
            model.addAttribute("filteredTotal", result.filteredTotal());
            model.addAttribute("totalAll", result.totalAll());
            model.addAttribute("filteredUnread", result.filteredUnread());
            model.addAttribute("totalPages", result.totalPages());
            model.addAttribute("currentPage", result.currentPage());
            model.addAttribute("unreadCount", notificationService.unreadCount(email));
        } catch (Exception ex) {
            log.warn("Notifications list failed for {}: {}", email, ex.getMessage());
            model.addAttribute("notifications", List.of());
            model.addAttribute("categoryCounts", Collections.emptyMap());
            model.addAttribute("filteredTotal", 0);
            model.addAttribute("totalAll", 0);
            model.addAttribute("filteredUnread", 0);
            model.addAttribute("totalPages", 0);
            model.addAttribute("currentPage", 0);
            model.addAttribute("unreadCount", 0L);
        }

        return "notifications/list";
    }

    @GetMapping("/notifications/{id}/open")
    public String open(
            @PathVariable Long id,
            @NonNull Authentication authentication) {

        try {
            String link = notificationService.open(id, authentication.getName());
            return "redirect:" + link;
        } catch (Exception ex) {
            return "redirect:/notifications";
        }
    }

    @PostMapping("/notifications/read-all")
    public String markAllRead(
            @NonNull Authentication authentication,
            @RequestHeader(value = "Referer", required = false) String referer) {

        try {
            notificationService.markAllRead(authentication.getName());
        } catch (Exception ex) {
            // Prefer returning to the list over a 500 on a mark-read failure.
        }

        if (referer != null && !referer.isBlank()) {
            return "redirect:" + referer;
        }
        return "redirect:/notifications";
    }
}
