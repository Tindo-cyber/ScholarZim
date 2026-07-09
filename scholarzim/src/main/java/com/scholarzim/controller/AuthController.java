package com.scholarzim.controller;

import com.scholarzim.dto.ForgotPasswordRequest;
import com.scholarzim.dto.ProviderRegisterRequest;
import com.scholarzim.dto.RegisterRequest;
import com.scholarzim.dto.ResetPasswordRequest;
import com.scholarzim.util.ProviderOrgType;
import com.scholarzim.service.AuthService;
import com.scholarzim.service.PasswordResetService;
import com.scholarzim.service.PlatformStatsService;
import com.scholarzim.service.ProviderRegistrationService;
import com.scholarzim.service.RegistrationException;
import jakarta.validation.Valid;
import org.springframework.lang.NonNull;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.validation.BindingResult;
import org.springframework.web.bind.annotation.*;
import org.springframework.web.multipart.MultipartFile;
import org.springframework.web.servlet.mvc.support.RedirectAttributes;


@Controller
public class AuthController {

    private final AuthService authService;
    private final PasswordResetService passwordResetService;
    private final ProviderRegistrationService providerRegistrationService;
    private final PlatformStatsService platformStatsService;
    private final boolean demoLoginEnabled;

    public AuthController(
            AuthService authService,
            PasswordResetService passwordResetService,
            ProviderRegistrationService providerRegistrationService,
            PlatformStatsService platformStatsService,
            @Value("${scholarzim.demo.seed:true}") boolean demoLoginEnabled) {

        this.authService = authService;
        this.passwordResetService = passwordResetService;
        this.providerRegistrationService = providerRegistrationService;
        this.platformStatsService = platformStatsService;
        this.demoLoginEnabled = demoLoginEnabled;
    }

    @GetMapping("/register")
    public String showRegisterPage(
            @ModelAttribute("registerRequest") RegisterRequest registerRequest,
            BindingResult bindingResult,
            Model model) {

        // BindingResult must be present so Thymeleaf #fields works on first GET.
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
    public String showProviderRegister(
            @ModelAttribute("registerRequest") ProviderRegisterRequest registerRequest,
            BindingResult bindingResult,
            Model model) {

        // BindingResult must be present so Thymeleaf #fields works on first GET.
        model.addAttribute("organisationTypes", ProviderOrgType.ALL);
        return "auth/register-provider";
    }

    @PostMapping("/register/provider")
    public String registerProvider(
            @Valid @ModelAttribute("registerRequest") ProviderRegisterRequest request,
            BindingResult bindingResult,
            @RequestParam(value = "certificate", required = false) MultipartFile certificate,
            RedirectAttributes redirect,
            Model model) {

        if (certificate == null || certificate.isEmpty()) {
            bindingResult.reject("certificateRequired", "Registration certificate (PDF) is required.");
        }

        if (bindingResult.hasErrors()) {
            model.addAttribute("organisationTypes", ProviderOrgType.ALL);
            return "auth/register-provider";
        }

        try {
            providerRegistrationService.registerProvider(request, certificate);
            redirect.addFlashAttribute("successMessage",
                    "Application submitted. An admin will review your registration documents.");
        } catch (RegistrationException ex) {
            bindingResult.reject("registrationError", registrationErrorMessage(ex));
            model.addAttribute("organisationTypes", ProviderOrgType.ALL);
            return "auth/register-provider";
        }

        return "redirect:/login?role=provider&pending=1";
    }

    @GetMapping("/login")
    public String loginPage(
            @RequestParam(name = "role", required = false, defaultValue = "student") String role,
            @RequestParam(name = "pending", required = false) Boolean pending,
            @RequestParam(name = "error", required = false) String error,
            Model model) {

        model.addAttribute("stats", platformStatsService.getPublicStats());
        model.addAttribute("demoLoginEnabled", demoLoginEnabled);
        model.addAttribute("loginRole", "provider".equalsIgnoreCase(role) ? "provider" : "student");
        model.addAttribute("pendingRegistration", Boolean.TRUE.equals(pending));
        model.addAttribute("loginError", error);
        return "auth/login";
    }

    @GetMapping("/forgot-password")
    public String forgotPasswordPage(
            @ModelAttribute("forgotRequest") ForgotPasswordRequest forgotRequest,
            BindingResult bindingResult) {

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
    public String resetPasswordPage(
            @PathVariable String token,
            @ModelAttribute("resetRequest") ResetPasswordRequest resetRequest,
            BindingResult bindingResult) {

        resetRequest.setToken(token);
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
