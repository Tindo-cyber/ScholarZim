package com.scholarzim.controller;

import com.scholarzim.util.ErrorPageSupport;
import com.scholarzim.util.LayoutViewUtil;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.GetMapping;


@Controller
public class ErrorPageController {

    @GetMapping("/403")
    public String forbidden(Model model) {
        model.addAttribute("status", 403);
        model.addAttribute("errorType", ErrorPageSupport.PERMISSION_DENIED);
        model.addAttribute("homeUrl", ErrorPageSupport.homeUrl());
        model.addAttribute("pageTitle", "Permission denied");
        return LayoutViewUtil.errorView();
    }
}
