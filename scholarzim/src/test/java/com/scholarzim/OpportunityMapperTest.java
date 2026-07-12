package com.scholarzim;

import com.scholarzim.entity.Opportunity;
import com.scholarzim.util.OpportunityMapper;
import org.junit.jupiter.api.Test;

import java.time.LocalDate;

import static org.junit.jupiter.api.Assertions.assertEquals;

class OpportunityMapperTest {

    @Test
    void mapsOpportunityFieldsToApiDto() {
        Opportunity opportunity = new Opportunity();
        opportunity.setOpportunityId(42L);
        opportunity.setTitle("STEM Grant");
        opportunity.setDescription("Full funding");
        opportunity.setProviderName("UZ Foundation");
        opportunity.setEducationLevel("Undergraduate");
        opportunity.setFundingType("Full");
        opportunity.setCountry("Zimbabwe");
        opportunity.setTargetField("Engineering");
        opportunity.setDeadline(LocalDate.of(2026, 12, 31));
        opportunity.setStatus("ACTIVE");

        var dto = OpportunityMapper.toApiDto(opportunity);

        assertEquals(42L, dto.getId());
        assertEquals("STEM Grant", dto.getTitle());
        assertEquals("UZ Foundation", dto.getProviderName());
        assertEquals("ACTIVE", dto.getStatus());
    }
}
