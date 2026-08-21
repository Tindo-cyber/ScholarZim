package com.scholarzim.config;

import org.springframework.boot.autoconfigure.flyway.FlywayMigrationStrategy;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;

@Configuration
public class FlywayConfig {

    /**
     * Clears failed migration records (e.g. after a bad local run or prod deploy)
     * before applying pending scripts. Safe when pending scripts are idempotent
     * or the failed version was already partially applied and marked repaired.
     */
    @Bean
    public FlywayMigrationStrategy flywayMigrationStrategy() {
        return flyway -> {
            flyway.repair();
            flyway.migrate();
        };
    }
}
