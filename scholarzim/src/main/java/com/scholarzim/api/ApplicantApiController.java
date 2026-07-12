package com.scholarzim.api;

import com.scholarzim.dto.OpportunityApiDTO;
import com.scholarzim.dto.PageResult;
import com.scholarzim.dto.ScoredOpportunityDTO;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.service.RecommendationService;
import com.scholarzim.service.SavedScholarshipService;
import com.scholarzim.util.OpportunityMapper;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.security.SecurityRequirement;
import io.swagger.v3.oas.annotations.tags.Tag;
import org.springframework.lang.NonNull;
import org.springframework.security.access.prepost.PreAuthorize;
import org.springframework.security.core.Authentication;
import org.springframework.web.bind.annotation.*;

import java.util.List;
import java.util.Map;


@RestController
@RequestMapping("/api/applicant")
@Tag(name = "Applicant")
@SecurityRequirement(name = "sessionCookie")
@PreAuthorize("hasRole('APPLICANT')")
public class ApplicantApiController {

    private static final int MAX_PAGE_SIZE = 50;

    private final RecommendationService recommendationService;
    private final SavedScholarshipService savedScholarshipService;

    public ApplicantApiController(
            RecommendationService recommendationService,
            SavedScholarshipService savedScholarshipService) {

        this.recommendationService = recommendationService;
        this.savedScholarshipService = savedScholarshipService;
    }

    @GetMapping("/recommendations")
    @Operation(summary = "ScholarFit recommendations")
    public PageResult<Map<String, Object>> recommendations(
            @NonNull Authentication auth,
            @RequestParam(defaultValue = "0") int page,
            @RequestParam(defaultValue = "20") int size) {

        List<Map<String, Object>> all = recommendationService.recommendForApplicant(auth.getName()).stream()
                .map(this::toMatchResponse)
                .toList();
        return slice(all, page, size);
    }

    @GetMapping("/saved")
    @Operation(summary = "Saved scholarships")
    public PageResult<OpportunityApiDTO> saved(
            @NonNull Authentication auth,
            @RequestParam(defaultValue = "0") int page,
            @RequestParam(defaultValue = "20") int size) {

        List<OpportunityApiDTO> all = savedScholarshipService.listSaved(auth.getName()).stream()
                .map(OpportunityMapper::toApiDto)
                .toList();
        return slice(all, page, size);
    }

    @PostMapping("/saved/{id}")
    @Operation(summary = "Save a scholarship")
    public Map<String, String> save(@PathVariable Long id, @NonNull Authentication auth) {
        savedScholarshipService.save(auth.getName(), id);
        return Map.of("status", "saved");
    }

    @DeleteMapping("/saved/{id}")
    @Operation(summary = "Remove a saved scholarship")
    public Map<String, String> unsave(@PathVariable Long id, @NonNull Authentication auth) {
        savedScholarshipService.remove(auth.getName(), id);
        return Map.of("status", "removed");
    }

    private Map<String, Object> toMatchResponse(ScoredOpportunityDTO scored) {
        Opportunity opp = scored.getOpportunity();
        return Map.of(
                "scholarship", OpportunityMapper.toApiDto(opp),
                "matchScore", scored.getMatchScore(),
                "breakdown", scored.getBreakdown());
    }

    private <T> PageResult<T> slice(List<T> all, int page, int size) {
        int safePage = Math.max(0, page);
        int safeSize = Math.min(MAX_PAGE_SIZE, Math.max(1, size));
        int from = Math.min(safePage * safeSize, all.size());
        int to = Math.min(from + safeSize, all.size());
        return new PageResult<>(all.subList(from, to), safePage, safeSize, all.size());
    }
}
