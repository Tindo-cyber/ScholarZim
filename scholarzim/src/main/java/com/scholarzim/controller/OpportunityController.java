package com.scholarzim.controller;

import com.scholarzim.dto.OpportunityRequest;
import com.scholarzim.dto.OpportunitySearchRequest;
import com.scholarzim.service.OpportunityService;
import com.scholarzim.util.FormOptions;
import jakarta.validation.Valid;
import org.springframework.lang.NonNull;
import org.springframework.security.core.Authentication;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.validation.BindingResult;
import org.springframework.web.bind.annotation.*;


@Controller
public class OpportunityController {

    private final OpportunityService opportunityService;

    public OpportunityController(OpportunityService opportunityService) {
        this.opportunityService = opportunityService;
    }

    @GetMapping("/opportunities")
    public String listOpportunities(
            @ModelAttribute("searchRequest") OpportunitySearchRequest searchRequest,
            Model model) {

        model.addAttribute("opportunities", opportunityService.searchOpportunities(searchRequest));
        model.addAttribute("providerNames", opportunityService.getProviderNames());
        return "opportunities/list";
    }

    @GetMapping("/opportunities/create")
    public String createPage(Model model) {
        OpportunityRequest request = new OpportunityRequest();
        request.setCountry(FormOptions.DEFAULT_COUNTRY);
        model.addAttribute("opportunityRequest", request);
        return "opportunities/create";
    }

    @PostMapping("/opportunities/create")
    public String createOpportunity(
            @Valid @ModelAttribute("opportunityRequest") OpportunityRequest request,
            BindingResult bindingResult,
            @NonNull Authentication authentication) {

        if (bindingResult.hasErrors()) {
            return "opportunities/create";
        }

        opportunityService.createOpportunity(request, authentication.getName());
        return "redirect:/provider/dashboard";
    }
}
