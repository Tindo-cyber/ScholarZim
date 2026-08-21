package com.scholarzim;

import com.scholarzim.support.FlywayItSupport;
import com.scholarzim.support.FlywayMigrationAssertions;
import com.zaxxer.hikari.HikariDataSource;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.condition.EnabledIfEnvironmentVariable;
import org.springframework.jdbc.core.JdbcTemplate;

class FlywayMigrationIT {

    @Test
    @EnabledIfEnvironmentVariable(named = "MYSQL_URL", matches = ".+")
    void migrationsApplyThroughV10() throws Exception {
        HikariDataSource dataSource = FlywayItSupport.createDataSource();
        try {
            FlywayItSupport.resetDatabase(dataSource);
            FlywayItSupport.runBaseline(dataSource);
            FlywayItSupport.repairAndMigrate(dataSource);

            FlywayMigrationAssertions.assertMigrationsAppliedThroughV10(new JdbcTemplate(dataSource));
        } finally {
            dataSource.close();
        }
    }
}
