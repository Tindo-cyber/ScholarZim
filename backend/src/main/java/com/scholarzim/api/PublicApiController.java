package com.scholarzim.api;

import com.scholarzim.dto.OpportunityApiDTO;
import com.scholarzim.dto.OpportunitySearchRequest;
import com.scholarzim.dto.PageResult;
import com.scholarzim.dto.PlatformStatsDTO;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.exception.ResourceNotFoundException;
import com.scholarzim.service.OpportunityService;
import com.scholarzim.service.PlatformStatsService;
import com.scholarzim.util.OpportunityMapper;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.tags.Tag;
import jakarta.validation.Valid;
import org.springframework.web.bind.annotation.*;

import java.util.List;


@RestController
@RequestMapping("/api/public")
@Tag(name = "Public")
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
    @Operation(summary = "Platform statistics", description = "Aggregate counts for scholarships, users, and applications.")
    public PlatformStatsDTO stats() {
        return platformStatsService.getPublicStats();
    }

    @GetMapping("/scholarships")
    @Operation(summary = "Search scholarships", description = "Paginated list of active scholarships with optional filters.")
    public PageResult<OpportunityApiDTO> scholarships(@Valid OpportunitySearchRequest search) {
        List<Opportunity> matches = opportunityService.searchOpportunities(search);
        int page = Math.max(0, search.getPage());
        int size = Math.min(50, Math.max(1, search.getSize()));
        int from = Math.min(page * size, matches.size());
        int to = Math.min(from + size, matches.size());
        List<OpportunityApiDTO> content = matches.subList(from, to).stream()
                .map(OpportunityMapper::toApiDto)
                .toList();
        return new PageResult<>(content, page, size, matches.size());
    }

    @GetMapping("/scholarships/{id}")
    @Operation(summary = "Scholarship detail")
    public OpportunityApiDTO scholarship(@PathVariable Long id) {
        Opportunity opp = opportunityService.findById(id)
                .orElseThrow(() -> new ResourceNotFoundException("Scholarship not found"));
        return OpportunityMapper.toApiDto(opp);
    }
}
