package com.scholarzim.controller;

import com.scholarzim.dto.OpportunitySearchRequest;
import com.scholarzim.exception.ResourceNotFoundException;
import com.scholarzim.service.OpportunityService;
import com.scholarzim.service.PlatformStatsService;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.ModelAttribute;
import org.springframework.web.bind.annotation.PathVariable;

@Controller
public class PublicController {

    private final PlatformStatsService platformStatsService;
    private final OpportunityService opportunityService;

    public PublicController(
            PlatformStatsService platformStatsService,
            OpportunityService opportunityService) {

        this.platformStatsService = platformStatsService;
        this.opportunityService = opportunityService;
    }

    @GetMapping("/")
    public String landing(Model model) {

        model.addAttribute("stats", platformStatsService.getPublicStats());
        model.addAttribute("featured", opportunityService.getActiveOpportunities().stream().limit(6).toList());
        return "public/index";
    }

    @GetMapping("/scholarships")
    public String publicBrowse(
            @ModelAttribute("searchRequest") OpportunitySearchRequest searchRequest,
            Model model) {

        model.addAttribute("stats", platformStatsService.getPublicStats());
        model.addAttribute("opportunities", opportunityService.searchOpportunities(searchRequest));
        model.addAttribute("providerNames", opportunityService.getProviderNames());
        return "public/scholarships";
    }

    @GetMapping("/scholarships/{id}")
    public String publicDetail(@PathVariable Long id, Model model) {

        model.addAttribute("opportunity", opportunityService.findById(id)
                .orElseThrow(() -> new ResourceNotFoundException("Scholarship not found.")));
        return "public/detail";
    }
}
