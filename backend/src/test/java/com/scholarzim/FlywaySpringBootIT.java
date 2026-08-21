package com.scholarzim;

import com.scholarzim.support.FlywayItContextInitializer;
import com.scholarzim.support.FlywayMigrationAssertions;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.condition.EnabledIfEnvironmentVariable;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.jdbc.core.JdbcTemplate;
import org.springframework.test.context.ActiveProfiles;
import org.springframework.test.context.ContextConfiguration;

@SpringBootTest
@ActiveProfiles("flyway-it")
@ContextConfiguration(initializers = FlywayItContextInitializer.class)
@EnabledIfEnvironmentVariable(named = "MYSQL_URL", matches = ".+")
class FlywaySpringBootIT {

    @Autowired
    private JdbcTemplate jdbcTemplate;

    @Test
    void springContextStartsWithFlywayMigrationsThroughV10() {
        FlywayMigrationAssertions.assertMigrationsAppliedThroughV10(jdbcTemplate);
    }
}
