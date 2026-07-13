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

    public static void assertMigrationsAppliedThroughV10(JdbcTemplate jdbc) {
        List<String> versions = jdbc.queryForList(
                "SELECT version FROM flyway_schema_history WHERE success = 1 ORDER BY installed_rank",
                String.class);

        Set<String> applied = new HashSet<>(versions);
        assertTrue(applied.containsAll(Set.of("1", "2", "3", "4", "5", "6", "7", "8", "9", "10")),
                () -> "Expected V1–V10 to be applied, but found: " + applied);

        Integer providerUserIndex = jdbc.queryForObject(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS "
                        + "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'opportunities' "
                        + "AND INDEX_NAME = 'idx_opportunities_provider_user_id'",
                Integer.class);
        assertTrue(providerUserIndex != null && providerUserIndex > 0,
                "idx_opportunities_provider_user_id should exist after V10");
    }

    public static void assertMigrationsAppliedThroughV7(JdbcTemplate jdbc) {
        List<String> versions = jdbc.queryForList(
                "SELECT version FROM flyway_schema_history WHERE success = 1 ORDER BY installed_rank",
                String.class);

        Set<String> applied = new HashSet<>(versions);
        assertTrue(applied.containsAll(Set.of("1", "2", "3", "4", "5", "6", "7")),
                () -> "Expected V1–V7 to be applied, but found: " + applied);
        assertEquals("7", versions.get(versions.size() - 1));

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

        Integer totpColumn = jdbc.queryForObject(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS "
                        + "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' "
                        + "AND COLUMN_NAME = 'totp_secret'",
                Integer.class);
        assertTrue(totpColumn != null && totpColumn == 0,
                "totp_secret column should be removed after V6");

        Integer emailIndex = jdbc.queryForObject(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS "
                        + "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' "
                        + "AND INDEX_NAME = 'uk_users_email'",
                Integer.class);
        assertTrue(emailIndex != null && emailIndex > 0,
                "uk_users_email index should exist after V7");
    }

    /** @deprecated use {@link #assertMigrationsAppliedThroughV7(JdbcTemplate)} */
    @Deprecated
    public static void assertMigrationsAppliedThroughV5(JdbcTemplate jdbc) {
        assertMigrationsAppliedThroughV7(jdbc);
    }
}
