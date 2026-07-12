package com.scholarzim.controller;

import com.scholarzim.dto.ForgotPasswordRequest;
import com.scholarzim.dto.ProviderRegisterRequest;
import com.scholarzim.dto.RegisterRequest;
import com.scholarzim.dto.ResetPasswordRequest;
import com.scholarzim.util.ProviderOrgType;
import com.scholarzim.service.AuthService;
import com.scholarzim.service.EmailVerificationService;
import com.scholarzim.service.PasswordResetService;
import com.scholarzim.service.PlatformStatsService;
import com.scholarzim.service.ProviderRegistrationService;
import com.scholarzim.service.RegistrationException;
import jakarta.validation.Valid;
import org.springframework.lang.NonNull;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.validation.BeanPropertyBindingResult;
import org.springframework.validation.BindingResult;
import org.springframework.web.bind.annotation.*;
import org.springframework.web.multipart.MultipartFile;
import org.springframework.web.servlet.mvc.support.RedirectAttributes;


@Controller
public class AuthController {

    private final AuthService authService;
    private final PasswordResetService passwordResetService;
    private final EmailVerificationService emailVerificationService;
    private final ProviderRegistrationService providerRegistrationService;
    private final PlatformStatsService platformStatsService;
    private final boolean demoLoginEnabled;
    private final boolean emailVerificationRequired;

    public AuthController(
            AuthService authService,
            PasswordResetService passwordResetService,
            EmailVerificationService emailVerificationService,
            ProviderRegistrationService providerRegistrationService,
            PlatformStatsService platformStatsService,
            @Value("${scholarzim.demo.seed:true}") boolean demoLoginEnabled,
            @Value("${scholarzim.auth.email-verification-required:true}") boolean emailVerificationRequired) {

        this.authService = authService;
        this.passwordResetService = passwordResetService;
        this.emailVerificationService = emailVerificationService;
        this.providerRegistrationService = providerRegistrationService;
        this.platformStatsService = platformStatsService;
        this.demoLoginEnabled = demoLoginEnabled;
        this.emailVerificationRequired = emailVerificationRequired;
    }

    @GetMapping("/register")
    public String showRegisterPage(Model model) {
        RegisterRequest registerRequest = new RegisterRequest();
        model.addAttribute("registerRequest", registerRequest);
        model.addAttribute(BindingResult.MODEL_KEY_PREFIX + "registerRequest",
                new BeanPropertyBindingResult(registerRequest, "registerRequest"));
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

        if (emailVerificationRequired) {
            return "redirect:/login?registered&verify=1";
        }
        return "redirect:/login?registered";
    }

    @GetMapping("/register/provider")
    public String showProviderRegister(Model model) {
        ProviderRegisterRequest registerRequest = new ProviderRegisterRequest();
        model.addAttribute("registerRequest", registerRequest);
        model.addAttribute(BindingResult.MODEL_KEY_PREFIX + "registerRequest",
                new BeanPropertyBindingResult(registerRequest, "registerRequest"));
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
            @RequestParam(name = "logout", required = false) String logout,
            @RequestParam(name = "registered", required = false) String registered,
            @RequestParam(name = "verify", required = false) String verify,
            Model model) {

        String loginRole = "provider".equalsIgnoreCase(role) ? "provider" : "student";
        boolean credentialsError = error != null
                && (error.isBlank() || "credentials".equalsIgnoreCase(error) || "disabled".equalsIgnoreCase(error));

        model.addAttribute("stats", platformStatsService.getPublicStats());
        model.addAttribute("demoLoginEnabled", demoLoginEnabled);
        model.addAttribute("loginRole", loginRole);
        model.addAttribute("pendingRegistration", Boolean.TRUE.equals(pending));
        model.addAttribute("loginError", error);
        model.addAttribute("showCredentialsError", credentialsError);
        model.addAttribute("showLogoutMessage", logout != null);
        model.addAttribute("showRegisteredMessage", registered != null);
        model.addAttribute("showVerifyMessage", verify != null);
        return "auth/login";
    }

    @GetMapping("/verify-email/{token}")
    public String verifyEmail(@PathVariable String token, RedirectAttributes redirect) {
        try {
            emailVerificationService.verify(token);
            redirect.addFlashAttribute("successMessage", "Email verified. You can sign in now.");
        } catch (IllegalArgumentException ex) {
            redirect.addFlashAttribute("errorMessage", passwordResetErrorMessage(ex));
        }
        return "redirect:/login";
    }

    @PostMapping("/resend-verification")
    public String resendVerification(
            @RequestParam String email,
            RedirectAttributes redirect) {

        emailVerificationService.resend(email);
        redirect.addFlashAttribute("infoMessage",
                "If that email is registered and unverified, we sent a new verification link.");
        return "redirect:/login";
    }

    @GetMapping("/forgot-password")
    public String forgotPasswordPage(Model model) {
        ForgotPasswordRequest forgotRequest = new ForgotPasswordRequest();
        model.addAttribute("forgotRequest", forgotRequest);
        model.addAttribute(BindingResult.MODEL_KEY_PREFIX + "forgotRequest",
                new BeanPropertyBindingResult(forgotRequest, "forgotRequest"));
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
        ResetPasswordRequest resetRequest = new ResetPasswordRequest();
        resetRequest.setToken(token);
        model.addAttribute("resetRequest", resetRequest);
        model.addAttribute(BindingResult.MODEL_KEY_PREFIX + "resetRequest",
                new BeanPropertyBindingResult(resetRequest, "resetRequest"));
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
