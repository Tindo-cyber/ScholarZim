package com.scholarzim.api;

import com.scholarzim.dto.OpportunityApiDTO;
import com.scholarzim.dto.ScoredOpportunityDTO;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.service.RecommendationService;
import com.scholarzim.service.SavedScholarshipService;
import org.springframework.security.core.Authentication;
import org.springframework.web.bind.annotation.*;

import java.util.List;
import java.util.Map;

@RestController
@RequestMapping("/api/applicant")
public class ApplicantApiController {

    private final RecommendationService recommendationService;
    private final SavedScholarshipService savedScholarshipService;

    public ApplicantApiController(
            RecommendationService recommendationService,
            SavedScholarshipService savedScholarshipService) {

        this.recommendationService = recommendationService;
        this.savedScholarshipService = savedScholarshipService;
    }

    @GetMapping("/recommendations")
    public List<Map<String, Object>> recommendations(Authentication auth) {

        return recommendationService.recommendForApplicant(auth.getName()).stream()
                .map(this::toMatchResponse)
                .toList();
    }

    @GetMapping("/saved")
    public List<OpportunityApiDTO> saved(Authentication auth) {

        return savedScholarshipService.listSaved(auth.getName()).stream()
                .map(this::toDto)
                .toList();
    }

    @PostMapping("/saved/{id}")
    public Map<String, String> save(@PathVariable Long id, Authentication auth) {
        savedScholarshipService.save(auth.getName(), id);
        return Map.of("status", "saved");
    }

    @DeleteMapping("/saved/{id}")
    public Map<String, String> unsave(@PathVariable Long id, Authentication auth) {
        savedScholarshipService.remove(auth.getName(), id);
        return Map.of("status", "removed");
    }

    private Map<String, Object> toMatchResponse(ScoredOpportunityDTO scored) {
        Opportunity opp = scored.getOpportunity();
        return Map.of(
                "scholarship", toDto(opp),
                "matchScore", scored.getMatchScore(),
                "breakdown", scored.getBreakdown());
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
