package com.scholarzim.api;

import com.scholarzim.dto.OpportunityApiDTO;
import com.scholarzim.dto.OpportunitySearchRequest;
import com.scholarzim.dto.PlatformStatsDTO;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.exception.ResourceNotFoundException;
import com.scholarzim.service.OpportunityService;
import com.scholarzim.service.PlatformStatsService;
import org.springframework.web.bind.annotation.*;

import java.util.List;

@RestController
@RequestMapping("/api/public")
public class PublicApiController {

    private final PlatformStatsService platformStatsService;
    private final OpportunityService opportunityService;

    public PublicApiController(
            PlatformStatsService platformStatsService,
            OpportunityService opportunityService) {

        this.platformStatsService = platformStatsService;
        this.opportunityService = opportunityService;
    }

    @GetMapping("/stats")
    public PlatformStatsDTO stats() {
        return platformStatsService.getPublicStats();
    }

    @GetMapping("/scholarships")
    public List<OpportunityApiDTO> scholarships(OpportunitySearchRequest search) {
        return opportunityService.searchOpportunities(search).stream()
                .map(this::toDto)
                .toList();
    }

    @GetMapping("/scholarships/{id}")
    public OpportunityApiDTO scholarship(@PathVariable Long id) {
        Opportunity opp = opportunityService.findById(id)
                .orElseThrow(() -> new ResourceNotFoundException("Scholarship not found"));
        return toDto(opp);
    }

    private OpportunityApiDTO toDto(Opportunity opp) {
        OpportunityApiDTO dto = new OpportunityApiDTO();
        dto.setId(opp.getOpportunityId());
        dto.setTitle(opp.getTitle());
        dto.setDescription(opp.getDescription());
        dto.setProviderName(opp.getProviderName());
        dto.setEducationLevel(opp.getEducationLevel());
        dto.setFundingType(opp.getFundingType());
        dto.setCountry(opp.getCountry());
        dto.setTargetField(opp.getTargetField());
        dto.setDeadline(opp.getDeadline());
        dto.setStatus(opp.getStatus());
        return dto;
    }
}
