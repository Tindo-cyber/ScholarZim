package com.scholarzim.controller;

import com.scholarzim.util.ErrorPageSupport;
import com.scholarzim.util.LayoutViewUtil;
import jakarta.servlet.RequestDispatcher;
import jakarta.servlet.http.HttpServletRequest;
import org.springframework.boot.web.servlet.error.ErrorController;
import org.springframework.http.HttpStatus;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.RequestMapping;

import java.util.Optional;


@Controller
public class ScholarzimErrorController implements ErrorController {

    @RequestMapping("/error")
    public String handleError(HttpServletRequest request, Model model) {
        Integer status = statusCode(request);
        String errorType = ErrorPageSupport.resolveType(status);

        model.addAttribute("status", status != null ? status : 500);
        model.addAttribute("errorType", errorType);
        model.addAttribute("pageTitle", ErrorPageSupport.title(errorType));
        model.addAttribute("homeUrl", ErrorPageSupport.homeUrl());
        model.addAttribute("retryUrl", retryUrl(request));

        return LayoutViewUtil.errorView();
    }

    private static Integer statusCode(HttpServletRequest request) {
        Object status = request.getAttribute(RequestDispatcher.ERROR_STATUS_CODE);
        if (status instanceof Integer code) {
            return code;
        }
        if (status instanceof String text) {
            try {
                return Integer.parseInt(text);
            } catch (NumberFormatException ignored) {
                return HttpStatus.INTERNAL_SERVER_ERROR.value();
            }
        }
        return HttpStatus.INTERNAL_SERVER_ERROR.value();
    }

    private static String retryUrl(HttpServletRequest request) {
        return Optional.ofNullable((String) request.getAttribute(RequestDispatcher.ERROR_REQUEST_URI))
                .filter(uri -> !uri.isBlank() && !"/error".equals(uri))
                .orElse(null);
    }
}
