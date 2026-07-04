package com.scholarzim.support;

import com.zaxxer.hikari.HikariDataSource;
import org.springframework.context.ApplicationContextInitializer;
import org.springframework.context.ConfigurableApplicationContext;

public class FlywayItContextInitializer
        implements ApplicationContextInitializer<ConfigurableApplicationContext> {

    @Override
    public void initialize(ConfigurableApplicationContext applicationContext) {
        String mysqlUrl = System.getenv("MYSQL_URL");
        if (mysqlUrl == null || mysqlUrl.isBlank()) {
            return;
        }
        HikariDataSource dataSource = FlywayItSupport.createDataSource();
        try {
            FlywayItSupport.runBaseline(dataSource);
        } catch (Exception ex) {
            throw new IllegalStateException("Failed to apply Flyway IT baseline schema", ex);
        } finally {
            dataSource.close();
        }
    }
}
