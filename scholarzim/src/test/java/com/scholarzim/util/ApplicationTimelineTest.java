package com.scholarzim.util;

import com.scholarzim.entity.Application;
import com.scholarzim.entity.Opportunity;
import org.junit.jupiter.api.Test;

import java.time.LocalDateTime;

import static org.assertj.core.api.Assertions.assertThat;


class ApplicationTimelineTest {

    @Test
    void mapsStatusesToTimelineStages() {
        assertThat(ApplicationTimeline.currentStageIndex("SUBMITTED")).isZero();
        assertThat(ApplicationTimeline.currentStageIndex("UNDER_REVIEW")).isEqualTo(1);
        assertThat(ApplicationTimeline.currentStageIndex("SHORTLISTED")).isEqualTo(2);
        assertThat(ApplicationTimeline.currentStageIndex("WAITLISTED")).isEqualTo(2);
        assertThat(ApplicationTimeline.currentStageIndex("INTERVIEW")).isEqualTo(3);
        assertThat(ApplicationTimeline.currentStageIndex("APPROVED")).isEqualTo(4);
        assertThat(ApplicationTimeline.currentStageIndex("AWARDED")).isEqualTo(4);
    }

    @Test
    void progressPercentReflectsStage() {
        assertThat(ApplicationTimeline.progressPercent("SUBMITTED")).isZero();
        assertThat(ApplicationTimeline.progressPercent("UNDER_REVIEW")).isEqualTo(25);
        assertThat(ApplicationTimeline.progressPercent("SHORTLISTED")).isEqualTo(50);
        assertThat(ApplicationTimeline.progressPercent("INTERVIEW")).isEqualTo(75);
        assertThat(ApplicationTimeline.progressPercent("APPROVED")).isEqualTo(100);
        assertThat(ApplicationTimeline.progressPercent("REJECTED")).isEqualTo(25);
    }

    @Test
    void stageStatesHighlightCurrentStage() {
        assertThat(ApplicationTimeline.isStageComplete("INTERVIEW", 1)).isTrue();
        assertThat(ApplicationTimeline.isStageCurrent("INTERVIEW", 3)).isTrue();
        assertThat(ApplicationTimeline.isStageUpcoming("INTERVIEW", 4)).isTrue();
    }

    @Test
    void stageDateLabelShowsSubmittedDate() {
        var app = new Application();
        app.setApplicationStatus("UNDER_REVIEW");
        app.setSubmittedAt(LocalDateTime.of(2026, 3, 15, 9, 0));

        assertThat(ApplicationTimeline.stageDateLabel(app, 0)).isEqualTo("15 Mar 2026");
        assertThat(ApplicationTimeline.stageDateLabel(app, 1)).isEqualTo("In progress");
    }

    @Test
    void trackerLabelUsesFriendlyNames() {
        assertThat(ApplicationTimeline.trackerLabel("APPROVED")).isEqualTo("Awarded");
        assertThat(ApplicationTimeline.trackerLabel("PENDING")).isEqualTo("Submitted");
        assertThat(ApplicationTimeline.trackerLabel("REJECTED")).isEqualTo("Rejected");
    }

    @Test
    void filtersAwardedStatusInPageSupport() {
        var opportunity = new Opportunity();
        opportunity.setTitle("STEM Award");
        opportunity.setProviderName("UZ");

        var approved = new Application();
        approved.setOpportunity(opportunity);
        approved.setApplicationStatus("APPROVED");

        var result = ApplicationPageSupport.buildPage(java.util.List.of(approved), "", "AWARDED", 0);

        assertThat(result.filteredTotal()).isEqualTo(1);
    }
}
