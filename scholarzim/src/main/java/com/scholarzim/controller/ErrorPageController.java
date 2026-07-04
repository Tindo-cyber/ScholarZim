package com.scholarzim.controller;

import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.GetMapping;


@Controller
public class ErrorPageController {

    @GetMapping("/403")
    public String forbidden(Model model) {
        model.addAttribute("status", 403);
        model.addAttribute("error", "You do not have permission to access this page.");
        return "error";
    }
}
