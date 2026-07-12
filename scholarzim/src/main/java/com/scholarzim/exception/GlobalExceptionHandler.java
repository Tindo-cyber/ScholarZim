package com.scholarzim.exception;

import com.scholarzim.service.RegistrationException;
import com.scholarzim.util.ErrorPageSupport;
import com.scholarzim.util.LayoutViewUtil;
import jakarta.servlet.http.HttpServletRequest;
import lombok.extern.slf4j.Slf4j;
import org.springframework.dao.DataIntegrityViolationException;
import org.springframework.security.access.AccessDeniedException;
import org.springframework.ui.Model;
import org.springframework.http.HttpStatus;
import org.springframework.web.bind.annotation.ControllerAdvice;
import org.springframework.web.bind.annotation.ExceptionHandler;
import org.springframework.web.bind.annotation.ResponseStatus;
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
    @ResponseStatus(HttpStatus.NOT_FOUND)
    public String handleNotFound(ResourceNotFoundException ex, Model model, HttpServletRequest request) {

        log.warn("Resource not found: {}", ex.getMessage());
        populateErrorModel(model, request, 404);
        return LayoutViewUtil.errorView();
    }

    @ExceptionHandler(NoSuchElementException.class)
    @ResponseStatus(HttpStatus.NOT_FOUND)
    public String handleNoSuchElement(NoSuchElementException ex, Model model, HttpServletRequest request) {

        log.warn("No such element: {}", ex.getMessage());
        populateErrorModel(model, request, 404);
        return LayoutViewUtil.errorView();
    }

    @ExceptionHandler(AccessDeniedException.class)
    @ResponseStatus(HttpStatus.FORBIDDEN)
    public String handleAccessDenied(AccessDeniedException ex, Model model, HttpServletRequest request) {

        log.warn("Access denied: {}", ex.getMessage());
        populateErrorModel(model, request, 403);
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

    @ExceptionHandler(AdminOperationException.class)
    public String handleAdminOperation(
            AdminOperationException ex,
            RedirectAttributes redirect) {

        log.warn("Admin operation rejected: {}", ex.getMessage());
        redirect.addFlashAttribute("errorMessage", ex.getMessage());
        return "redirect:/admin/dashboard";
    }

    @ExceptionHandler(Exception.class)
    @ResponseStatus(HttpStatus.INTERNAL_SERVER_ERROR)
    public String handleGeneral(Exception ex, Model model, HttpServletRequest request) {

        if (isTemplateRenderingFailure(ex)) {
            log.error("Template rendering failed", ex);
            throw new IllegalStateException("Template rendering failed", ex);
        }

        log.error("Unhandled exception", ex);
        populateErrorModel(model, request, 500);
        return LayoutViewUtil.errorView();
    }

    private static void populateErrorModel(Model model, HttpServletRequest request, int status) {
        model.addAttribute("status", status);
        String errorType = ErrorPageSupport.resolveType(status);
        model.addAttribute("errorType", errorType);
        model.addAttribute("pageTitle", ErrorPageSupport.title(errorType));
        model.addAttribute("homeUrl", ErrorPageSupport.homeUrl());
        String requestUri = request.getRequestURI();
        model.addAttribute("retryUrl",
                requestUri != null && !requestUri.isBlank() ? requestUri : null);
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
