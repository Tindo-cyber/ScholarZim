package com.scholarzim.controller;

import com.scholarzim.util.ErrorPageSupport;
import com.scholarzim.util.LayoutViewUtil;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RequestMethod;


@Controller
public class ErrorPageController {

    // Spring Security's accessDeniedPage("/403") forwards internally, preserving the
    // original request method — a POST blocked by CSRF or role checks must still reach
    // this handler, not just GET, or it 500s with HttpRequestMethodNotSupportedException.
    @RequestMapping(value = "/403", method = {RequestMethod.GET, RequestMethod.POST})
    public String forbidden(Model model) {
        model.addAttribute("status", 403);
        model.addAttribute("errorType", ErrorPageSupport.PERMISSION_DENIED);
        model.addAttribute("homeUrl", ErrorPageSupport.homeUrl());
        model.addAttribute("pageTitle", "Permission denied");
        return LayoutViewUtil.errorView();
    }
}
