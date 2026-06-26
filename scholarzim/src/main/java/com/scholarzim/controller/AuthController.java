package com.scholarzim.controller;

import com.scholarzim.dto.ForgotPasswordRequest;
import com.scholarzim.dto.RegisterRequest;
import com.scholarzim.dto.ResetPasswordRequest;
import com.scholarzim.service.AuthService;
import com.scholarzim.service.PasswordResetService;
import com.scholarzim.service.PlatformStatsService;
import com.scholarzim.service.ProviderRegistrationService;
import com.scholarzim.service.RegistrationException;
import jakarta.validation.Valid;
import org.springframework.lang.NonNull;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.validation.BindingResult;
import org.springframework.web.bind.annotation.*;
import org.springframework.web.servlet.mvc.support.RedirectAttributes;

@Controller
public class AuthController {

    private final AuthService authService;
    private final PasswordResetService passwordResetService;
    private final ProviderRegistrationService providerRegistrationService;
    private final PlatformStatsService platformStatsService;

    public AuthController(
            AuthService authService,
            PasswordResetService passwordResetService,
            ProviderRegistrationService providerRegistrationService,
            PlatformStatsService platformStatsService) {

        this.authService = authService;
        this.passwordResetService = passwordResetService;
        this.providerRegistrationService = providerRegistrationService;
        this.platformStatsService = platformStatsService;
    }

    @GetMapping("/register")
    public String showRegisterPage(Model model) {
        model.addAttribute("registerRequest", new RegisterRequest());
        model.addAttribute("stats", platformStatsService.getPublicStats());
        return "auth/register";
    }

    @PostMapping("/register")
    public String register(
            @Valid @ModelAttribute("registerRequest") RegisterRequest request,
            BindingResult bindingResult) {

        if (bindingResult.hasErrors()) {
            return "auth/register";
        }

        try {
            authService.registerApplicant(request);
        } catch (RegistrationException ex) {
            bindingResult.reject("registrationError", registrationErrorMessage(ex));
            return "auth/register";
        }

        return "redirect:/login?registered";
    }

    @GetMapping("/register/provider")
    public String showProviderRegister(Model model) {
        model.addAttribute("registerRequest", new RegisterRequest());
        return "auth/register-provider";
    }

    @PostMapping("/register/provider")
    public String registerProvider(
            @Valid @ModelAttribute("registerRequest") RegisterRequest request,
            BindingResult bindingResult,
            RedirectAttributes redirect) {

        if (bindingResult.hasErrors()) {
            return "auth/register-provider";
        }

        try {
            providerRegistrationService.registerProvider(request);
            redirect.addFlashAttribute("successMessage",
                    "Provider application submitted. An admin will review your account.");
        } catch (RegistrationException ex) {
            bindingResult.reject("registrationError", registrationErrorMessage(ex));
            return "auth/register-provider";
        }

        return "redirect:/login";
    }

    @GetMapping("/login")
    public String loginPage(Model model) {
        model.addAttribute("stats", platformStatsService.getPublicStats());
        return "auth/login";
    }

    @GetMapping("/forgot-password")
    public String forgotPasswordPage(Model model) {
        model.addAttribute("forgotRequest", new ForgotPasswordRequest());
        return "auth/forgot-password";
    }

    @PostMapping("/forgot-password")
    public String forgotPassword(
            @Valid @ModelAttribute("forgotRequest") ForgotPasswordRequest request,
            BindingResult bindingResult,
            RedirectAttributes redirect) {

        if (bindingResult.hasErrors()) {
            return "auth/forgot-password";
        }

        passwordResetService.requestReset(request.getEmail());
        redirect.addFlashAttribute("successMessage",
                "If that email exists, we sent a reset link.");
        return "redirect:/login";
    }

    @GetMapping("/reset-password/{token}")
    public String resetPasswordPage(@PathVariable String token, Model model) {
        ResetPasswordRequest req = new ResetPasswordRequest();
        req.setToken(token);
        model.addAttribute("resetRequest", req);
        return "auth/reset-password";
    }

    @PostMapping("/reset-password/{token}")
    public String resetPassword(
            @PathVariable String token,
            @Valid @ModelAttribute("resetRequest") ResetPasswordRequest request,
            BindingResult bindingResult,
            Model model,
            RedirectAttributes redirect) {

        request.setToken(token);

        if (bindingResult.hasErrors()) {
            return "auth/reset-password";
        }

        try {
            passwordResetService.resetPassword(token, request.getPassword(), request.getConfirmPassword());
        } catch (IllegalArgumentException ex) {
            bindingResult.reject("resetError", passwordResetErrorMessage(ex));
            return "auth/reset-password";
        }

        redirect.addFlashAttribute("successMessage", "Password updated. You can sign in now.");
        return "redirect:/login";
    }

    @NonNull
    private static String registrationErrorMessage(RegistrationException ex) {
        String message = ex.getMessage();
        return message != null ? message : "Registration failed.";
    }

    @NonNull
    private static String passwordResetErrorMessage(IllegalArgumentException ex) {
        String message = ex.getMessage();
        return message != null ? message : "Password reset failed.";
    }
}
