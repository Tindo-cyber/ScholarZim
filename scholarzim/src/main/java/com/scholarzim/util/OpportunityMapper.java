package com.scholarzim.util;

import com.scholarzim.dto.OpportunityApiDTO;
import com.scholarzim.entity.Opportunity;


public final class OpportunityMapper {

    private OpportunityMapper() {
    }

    public static OpportunityApiDTO toApiDto(Opportunity opp) {
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
