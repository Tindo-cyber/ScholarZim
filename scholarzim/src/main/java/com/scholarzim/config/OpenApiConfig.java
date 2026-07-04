package com.scholarzim.config;

import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;

import io.swagger.v3.oas.models.OpenAPI;
import io.swagger.v3.oas.models.info.Info;

@Configuration
public class OpenApiConfig {

    @Bean
    public OpenAPI scholarZimOpenApi() {
        return new OpenAPI().info(new Info()
                .title("ScholarZim API")
                .description("Public and applicant REST endpoints for ScholarZim")
                .version("1.0"));
    }
}
