package com.scholarzim.support;

import org.springframework.jdbc.core.JdbcTemplate;

import java.util.HashSet;
import java.util.List;
import java.util.Set;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;

public final class FlywayMigrationAssertions {

    private FlywayMigrationAssertions() {
    }

    public static void assertMigrationsAppliedThroughV5(JdbcTemplate jdbc) {
        List<String> versions = jdbc.queryForList(
                "SELECT version FROM flyway_schema_history WHERE success = 1 ORDER BY installed_rank",
                String.class);

        Set<String> applied = new HashSet<>(versions);
        assertTrue(applied.containsAll(Set.of("1", "2", "3", "4", "5")),
                () -> "Expected V1–V5 to be applied, but found: " + applied);
        assertEquals("5", versions.get(versions.size() - 1));

        Integer resultsColumn = jdbc.queryForObject(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS "
                        + "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicant_profiles' "
                        + "AND COLUMN_NAME = 'results_certificate_path'",
                Integer.class);
        assertTrue(resultsColumn != null && resultsColumn > 0,
                "results_certificate_path column should exist after V5");

        Integer providerTable = jdbc.queryForObject(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES "
                        + "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'provider_profiles'",
                Integer.class);
        assertTrue(providerTable != null && providerTable > 0,
                "provider_profiles table should exist after V4");
    }
}
