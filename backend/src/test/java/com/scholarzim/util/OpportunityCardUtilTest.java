package com.scholarzim.util;

import com.scholarzim.entity.Opportunity;
import org.junit.jupiter.api.Test;

import static org.assertj.core.api.Assertions.assertThat;


class OpportunityCardUtilTest {

    @Test
    void logoInitialsFromProviderName() {
        assertThat(OpportunityCardUtil.logoInitials("University of Zimbabwe")).isEqualTo("UO");
        assertThat(OpportunityCardUtil.logoInitials("CBZ")).isEqualTo("CB");
    }

    @Test
    void eligibilitySummaryCombinesFields() {
        var opportunity = new Opportunity();
        opportunity.setEducationLevel("Undergraduate");
        opportunity.setTargetField("Computer Science");
        opportunity.setCountry("Zimbabwe");

        assertThat(OpportunityCardUtil.eligibilitySummary(opportunity))
                .contains("Undergraduate")
                .contains("Computer Science")
                .contains("Zimbabwe");
    }
}
