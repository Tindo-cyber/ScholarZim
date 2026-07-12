package com.scholarzim.exception;

import com.scholarzim.service.RegistrationException;
import com.scholarzim.util.LayoutViewUtil;
import lombok.extern.slf4j.Slf4j;
import org.springframework.dao.DataIntegrityViolationException;
import org.springframework.security.access.AccessDeniedException;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.ControllerAdvice;
import org.springframework.web.bind.annotation.ExceptionHandler;
import org.springframework.web.servlet.mvc.support.RedirectAttributes;
import org.thymeleaf.exceptions.TemplateInputException;
import org.thymeleaf.exceptions.TemplateProcessingException;

import java.util.NoSuchElementException;


@Slf4j
@ControllerAdvice
public class GlobalExceptionHandler {

    @ExceptionHandler(DuplicateApplicationException.class)
    public String handleDuplicateApplication(
            DuplicateApplicationException ex,
            RedirectAttributes redirect) {

        log.warn("Duplicate application attempt: {}", ex.getMessage());
        redirect.addFlashAttribute("errorMessage", ex.getMessage());
        return "redirect:/opportunities";
    }

    @ExceptionHandler(DataIntegrityViolationException.class)
    public String handleDataIntegrity(
            DataIntegrityViolationException ex,
            RedirectAttributes redirect) {

        log.warn("Data integrity violation: {}", ex.getMessage());
        redirect.addFlashAttribute(
                "errorMessage",
                "This action could not be completed — the record may already exist.");
        return "redirect:/opportunities";
    }

    @ExceptionHandler(ResourceNotFoundException.class)
    public String handleNotFound(ResourceNotFoundException ex, Model model) {

        log.warn("Resource not found: {}", ex.getMessage());
        model.addAttribute("status", 404);
        model.addAttribute("error", ex.getMessage());
        return LayoutViewUtil.errorView();
    }

    @ExceptionHandler(NoSuchElementException.class)
    public String handleNoSuchElement(NoSuchElementException ex, Model model) {

        log.warn("No such element: {}", ex.getMessage());
        model.addAttribute("status", 404);
        model.addAttribute("error", "The requested resource was not found.");
        return LayoutViewUtil.errorView();
    }

    @ExceptionHandler(AccessDeniedException.class)
    public String handleAccessDenied(AccessDeniedException ex, Model model) {

        log.warn("Access denied: {}", ex.getMessage());
        model.addAttribute("status", 403);
        model.addAttribute("error", ex.getMessage());
        return LayoutViewUtil.errorView();
    }

    @ExceptionHandler(InvalidStatusException.class)
    public String handleInvalidStatus(
            InvalidStatusException ex,
            RedirectAttributes redirect) {

        log.warn("Invalid status: {}", ex.getMessage());
        redirect.addFlashAttribute("errorMessage", ex.getMessage());
        return "redirect:/provider/applications";
    }

    @ExceptionHandler(RegistrationException.class)
    public String handleRegistration(
            RegistrationException ex,
            RedirectAttributes redirect) {

        log.warn("Registration error: {}", ex.getMessage());
        redirect.addFlashAttribute("errorMessage", ex.getMessage());
        return "redirect:/register";
    }

    @ExceptionHandler(Exception.class)
    public String handleGeneral(Exception ex, Model model) {

        if (isTemplateRenderingFailure(ex)) {
            log.error("Template rendering failed", ex);
            throw new IllegalStateException("Template rendering failed", ex);
        }

        log.error("Unhandled exception", ex);
        model.addAttribute("status", 500);
        model.addAttribute("error", "An unexpected error occurred. Please try again.");
        return LayoutViewUtil.errorView();
    }

    private static boolean isTemplateRenderingFailure(Throwable ex) {
        for (Throwable current = ex; current != null; current = current.getCause()) {
            if (current instanceof TemplateProcessingException
                    || current instanceof TemplateInputException) {
                return true;
            }
        }
        return false;
    }
}
