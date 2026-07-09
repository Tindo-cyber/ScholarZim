package com.scholarzim.controller;

import org.springframework.stereotype.Controller;
import org.springframework.web.bind.annotation.GetMapping;


@Controller
public class AccountSecurityController {

    @GetMapping("/account/security")
    public String securityPage() {
        return "account/security";
    }
}
