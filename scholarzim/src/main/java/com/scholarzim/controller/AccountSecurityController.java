package com.scholarzim.controller;

import com.scholarzim.service.TotpService;
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

    public AccountSecurityController(TotpService totpService) {
        this.totpService = totpService;
    }

    @GetMapping("/account/security")
    public String securityPage(Authentication auth, Model model) {
        String secret = totpService.generateSecret();
        model.addAttribute("secret", secret);
        model.addAttribute("qrUri", totpService.buildQrUri(auth.getName(), secret));
        return "account/security";
    }

    @PostMapping("/account/security/enable-2fa")
    public String enable2fa(
            @RequestParam String secret,
            @RequestParam String code,
            Authentication auth,
            RedirectAttributes redirect) {

        try {
            totpService.enableForUser(auth.getName(), secret, code);
            redirect.addFlashAttribute("successMessage", "Two-factor authentication enabled.");
        } catch (IllegalArgumentException ex) {
            redirect.addFlashAttribute("errorMessage", ex.getMessage());
        }
        return "redirect:/account/security";
    }

    @PostMapping("/account/security/disable-2fa")
    public String disable2fa(Authentication auth, RedirectAttributes redirect) {
        totpService.disableForUser(auth.getName());
        redirect.addFlashAttribute("successMessage", "Two-factor authentication disabled.");
        return "redirect:/account/security";
    }
}
