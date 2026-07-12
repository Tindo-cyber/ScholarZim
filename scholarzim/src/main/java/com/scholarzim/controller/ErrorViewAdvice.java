package com.scholarzim.controller;

import com.scholarzim.util.ErrorPageSupport;
import org.springframework.web.bind.annotation.ControllerAdvice;
import org.springframework.web.bind.annotation.ModelAttribute;


@ControllerAdvice
public class ErrorViewAdvice {

    @ModelAttribute("userHomeUrl")
    public String userHomeUrl() {
        return ErrorPageSupport.homeUrl();
    }

    @ModelAttribute("supportUrl")
    public String supportUrl() {
        return ErrorPageSupport.supportUrl();
    }

    @ModelAttribute("supportLabel")
    public String supportLabel() {
        return ErrorPageSupport.supportLabel();
    }
}
