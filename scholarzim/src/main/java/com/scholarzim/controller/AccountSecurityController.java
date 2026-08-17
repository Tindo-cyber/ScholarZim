package com.scholarzim.controller;

import com.scholarzim.entity.User;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.AuditService;
import com.scholarzim.util.AuditAction;
import org.springframework.lang.NonNull;
import org.springframework.security.core.Authentication;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.servlet.mvc.support.RedirectAttributes;


@Controller
public class AccountSecurityController {

    private final UserRepository userRepository;
    private final AuditService auditService;

    public AccountSecurityController(UserRepository userRepository, AuditService auditService) {
        this.userRepository = userRepository;
        this.auditService = auditService;
    }

    @GetMapping("/account/security")
    public String securityPage(@NonNull Authentication authentication, Model model) {
        userRepository.findByEmail(authentication.getName()).ifPresent(user -> {
            model.addAttribute("emailNotifyApplications", user.isEmailNotifyApplications());
            model.addAttribute("emailNotifyScholarships", user.isEmailNotifyScholarships());
            model.addAttribute("emailNotifySystem", user.isEmailNotifySystem());
        });
        return "account/security";
    }

    @PostMapping("/account/notification-preferences")
    public String saveNotificationPreferences(
            @RequestParam(name = "emailNotifyApplications", required = false) Boolean emailNotifyApplications,
            @RequestParam(name = "emailNotifyScholarships", required = false) Boolean emailNotifyScholarships,
            @RequestParam(name = "emailNotifySystem", required = false) Boolean emailNotifySystem,
            @NonNull Authentication authentication,
            RedirectAttributes redirect) {

        String email = authentication.getName();
        User user = userRepository.findByEmail(email).orElse(null);
        if (user != null) {
            user.setEmailNotifyApplications(Boolean.TRUE.equals(emailNotifyApplications));
            user.setEmailNotifyScholarships(Boolean.TRUE.equals(emailNotifyScholarships));
            user.setEmailNotifySystem(Boolean.TRUE.equals(emailNotifySystem));
            userRepository.save(user);
            auditService.log(email, AuditAction.NOTIFICATION_PREFERENCES_UPDATE, "USER", user.getUserId(),
                    "Updated email notification preferences");
        }

        redirect.addFlashAttribute("successMessage", "Notification preferences saved.");
        return "redirect:/account/security";
    }
}
