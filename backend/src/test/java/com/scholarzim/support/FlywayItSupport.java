package com.scholarzim.support;

import com.zaxxer.hikari.HikariDataSource;
import org.flywaydb.core.Flyway;

import javax.sql.DataSource;
import java.sql.Connection;
import java.sql.ResultSet;
import java.sql.Statement;
import java.util.ArrayList;
import java.util.List;
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

    /** Drop every table so each IT starts from a clean database. */
    public static void resetDatabase(DataSource dataSource) throws Exception {
        try (Connection conn = dataSource.getConnection();
             Statement st = conn.createStatement()) {
            st.execute("SET FOREIGN_KEY_CHECKS = 0");
            List<String> tables = new ArrayList<>();
            try (ResultSet rs = st.executeQuery(
                    "SELECT table_name FROM information_schema.tables "
                            + "WHERE table_schema = DATABASE()")) {
                while (rs.next()) {
                    tables.add(rs.getString(1));
                }
            }
            for (String table : tables) {
                st.execute("DROP TABLE IF EXISTS `" + table + "`");
            }
            st.execute("SET FOREIGN_KEY_CHECKS = 1");
        }
    }

    public static void runBaseline(DataSource dataSource) throws Exception {
        try (var conn = dataSource.getConnection()) {
            ScriptUtils.executeSqlScript(conn, new ClassPathResource("flyway-it-baseline.sql"));
        }
    }

    public static void repairAndMigrate(DataSource dataSource) {
        Flyway flyway = Flyway.configure()
                .dataSource(dataSource)
                .locations("classpath:db/migration")
                .baselineOnMigrate(true)
                .load();
        flyway.repair();
        flyway.migrate();
    }
}
