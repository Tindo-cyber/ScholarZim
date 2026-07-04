package com.scholarzim.controller;

import com.scholarzim.service.TotpService;
import org.springframework.lang.NonNull;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.security.core.Authentication;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.servlet.mvc.support.RedirectAttributes;


@Controller
public class AccountSecurityController {

    private final TotpService totpService;
    private final boolean twoFaEnabled;

    public AccountSecurityController(
            TotpService totpService,
            @Value("${scholarzim.security.2fa.enabled:false}") boolean twoFaEnabled) {
        this.totpService = totpService;
        this.twoFaEnabled = twoFaEnabled;
    }

    @GetMapping("/account/security")
    public String securityPage(@NonNull Authentication auth, Model model) {
        model.addAttribute("twoFaEnabled", twoFaEnabled);
        if (twoFaEnabled) {
            String secret = totpService.generateSecret();
            model.addAttribute("secret", secret);
            model.addAttribute("qrUri", totpService.buildQrUri(auth.getName(), secret));
        }
        return "account/security";
    }

    @PostMapping("/account/security/enable-2fa")
    public String enable2fa(
            @RequestParam String secret,
            @RequestParam String code,
            @NonNull Authentication auth,
            RedirectAttributes redirect) {

        if (!twoFaEnabled) {
            redirect.addFlashAttribute("errorMessage", "Two-factor authentication is not available yet.");
            return "redirect:/account/security";
        }

        try {
            totpService.enableForUser(auth.getName(), secret, code);
            redirect.addFlashAttribute("successMessage", "Two-factor authentication enabled.");
        } catch (IllegalArgumentException ex) {
            redirect.addFlashAttribute("errorMessage", ex.getMessage());
        }
        return "redirect:/account/security";
    }

    @PostMapping("/account/security/disable-2fa")
    public String disable2fa(@NonNull Authentication auth, RedirectAttributes redirect) {
        if (!twoFaEnabled) {
            redirect.addFlashAttribute("errorMessage", "Two-factor authentication is not available yet.");
            return "redirect:/account/security";
        }
        totpService.disableForUser(auth.getName());
        redirect.addFlashAttribute("successMessage", "Two-factor authentication disabled.");
        return "redirect:/account/security";
    }
}
