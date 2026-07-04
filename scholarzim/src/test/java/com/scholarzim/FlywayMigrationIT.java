package com.scholarzim;

import com.scholarzim.support.FlywayItSupport;
import com.scholarzim.support.FlywayMigrationAssertions;
import com.zaxxer.hikari.HikariDataSource;
import org.flywaydb.core.Flyway;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.condition.EnabledIfEnvironmentVariable;
import org.springframework.jdbc.core.JdbcTemplate;

class FlywayMigrationIT {

    @Test
    @EnabledIfEnvironmentVariable(named = "MYSQL_URL", matches = ".+")
    void migrationsApplyThroughV5() throws Exception {
        HikariDataSource dataSource = FlywayItSupport.createDataSource();
        try {
            FlywayItSupport.runBaseline(dataSource);

            Flyway.configure()
                    .dataSource(dataSource)
                    .locations("classpath:db/migration")
                    .baselineOnMigrate(true)
                    .load()
                    .migrate();

            FlywayMigrationAssertions.assertMigrationsAppliedThroughV5(new JdbcTemplate(dataSource));
        } finally {
            dataSource.close();
        }
    }
}
