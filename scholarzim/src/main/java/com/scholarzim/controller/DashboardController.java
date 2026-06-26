package com.scholarzim.controller;

import com.scholarzim.security.RoleRedirectUtil;
import org.springframework.security.core.Authentication;
import org.springframework.stereotype.Controller;
import org.springframework.web.bind.annotation.GetMapping;

@Controller
public class DashboardController {

    @GetMapping("/dashboard")
    public String legacyDashboard(Authentication authentication) {
        return "redirect:" + RoleRedirectUtil.getDashboardUrl(
                authentication.getAuthorities());
    }

}
