package com.scholarzim.dto;

import com.scholarzim.entity.Opportunity;
import lombok.Getter;
import lombok.Setter;

@Getter
@Setter
public class ScoredOpportunityDTO {

    private Opportunity opportunity;
    private int matchScore;
    private MatchBreakdownDTO breakdown;

    public ScoredOpportunityDTO(Opportunity opportunity, int matchScore, MatchBreakdownDTO breakdown) {
        this.opportunity = opportunity;
        this.matchScore = matchScore;
        this.breakdown = breakdown;
    }
}
