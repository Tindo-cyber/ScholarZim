package com.scholarzim.support;

import com.zaxxer.hikari.HikariDataSource;

import javax.sql.DataSource;
import java.util.Objects;

import org.springframework.core.io.ClassPathResource;
import org.springframework.jdbc.datasource.init.ScriptUtils;

public final class FlywayItSupport {

    private FlywayItSupport() {
    }

    public static HikariDataSource createDataSource() {
        HikariDataSource dataSource = new HikariDataSource();
        dataSource.setJdbcUrl(Objects.requireNonNull(System.getenv("MYSQL_URL")));
        dataSource.setUsername(System.getenv().getOrDefault("MYSQL_USER", "root"));
        dataSource.setPassword(System.getenv().getOrDefault("MYSQL_PASSWORD", "root"));
        return dataSource;
    }

    public static void runBaseline(DataSource dataSource) throws Exception {
        try (var conn = dataSource.getConnection()) {
            ScriptUtils.executeSqlScript(conn, new ClassPathResource("flyway-it-baseline.sql"));
        }
    }
}
