package com.scholarzim.controller;

import com.scholarzim.security.RoleRedirectUtil;
import com.scholarzim.security.TwoFactorAuthenticationFilter;
import com.scholarzim.service.TotpService;
import jakarta.servlet.http.HttpSession;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.lang.NonNull;
import org.springframework.security.core.Authentication;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.servlet.mvc.support.RedirectAttributes;

@Controller
public class LoginTwoFactorController {

    private final TotpService totpService;
    private final boolean twoFaEnabled;

    public LoginTwoFactorController(
            TotpService totpService,
            @Value("${scholarzim.security.2fa.enabled:false}") boolean twoFaEnabled) {

        this.totpService = totpService;
        this.twoFaEnabled = twoFaEnabled;
    }

    @GetMapping("/login/2fa-challenge")
    public String challengePage(@NonNull Authentication auth, Model model) {
        if (!twoFaEnabled || !totpService.requiresTwoFactor(auth.getName())) {
            return "redirect:" + RoleRedirectUtil.getDashboardUrl(auth.getAuthorities());
        }
        model.addAttribute("email", auth.getName());
        return "auth/two-factor-challenge";
    }

    @PostMapping("/login/2fa-challenge")
    public String verifyCode(
            @RequestParam String code,
            @NonNull Authentication auth,
            HttpSession session,
            RedirectAttributes redirect) {

        if (!twoFaEnabled) {
            return "redirect:" + RoleRedirectUtil.getDashboardUrl(auth.getAuthorities());
        }

        if (totpService.verifyForUser(auth.getName(), code)) {
            session.setAttribute(TwoFactorAuthenticationFilter.TWO_FA_VERIFIED, Boolean.TRUE);
            return "redirect:" + RoleRedirectUtil.getDashboardUrl(auth.getAuthorities());
        }

        redirect.addFlashAttribute("errorMessage", "Invalid verification code. Try again.");
        return "redirect:/login/2fa-challenge";
    }
}
