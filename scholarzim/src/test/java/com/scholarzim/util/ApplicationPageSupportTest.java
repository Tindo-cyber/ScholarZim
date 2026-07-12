package com.scholarzim.util;

import com.scholarzim.entity.Application;
import com.scholarzim.entity.Opportunity;
import org.junit.jupiter.api.Test;

import java.time.LocalDateTime;
import java.util.List;

import static org.assertj.core.api.Assertions.assertThat;


class ApplicationPageSupportTest {

    @Test
    void filtersByStatusAndPaginates() {
        var apps = List.of(
                app(1L, "Alpha Scholarship", "APPROVED", LocalDateTime.of(2026, 1, 1, 10, 0)),
                app(2L, "Beta Grant", "PENDING", LocalDateTime.of(2026, 2, 1, 10, 0)),
                app(3L, "Gamma Fund", "REJECTED", LocalDateTime.of(2026, 3, 1, 10, 0)));

        var result = ApplicationPageSupport.buildPage(apps, "", "PENDING", 0);

        assertThat(result.filteredTotal()).isEqualTo(1);
        assertThat(result.applications()).hasSize(1);
        assertThat(result.applications().getFirst().getOpportunity().getTitle()).isEqualTo("Beta Grant");
        assertThat(result.pendingCount()).isEqualTo(1);
    }

    @Test
    void filtersByQueryCaseInsensitive() {
        var apps = List.of(
                app(1L, "Zimbabwe STEM Award", "APPROVED", LocalDateTime.now()),
                app(2L, "Arts Bursary", "PENDING", LocalDateTime.now()));

        var result = ApplicationPageSupport.buildPage(apps, "stem", "", 0);

        assertThat(result.filteredTotal()).isEqualTo(1);
        assertThat(result.applications().getFirst().getOpportunity().getTitle())
                .isEqualTo("Zimbabwe STEM Award");
    }

    private static Application app(Long id, String title, String status, LocalDateTime submittedAt) {
        var opportunity = new Opportunity();
        opportunity.setTitle(title);
        opportunity.setProviderName("Provider " + id);

        var application = new Application();
        application.setApplicationId(id);
        application.setOpportunity(opportunity);
        application.setApplicationStatus(status);
        application.setSubmittedAt(submittedAt);
        return application;
    }
}
