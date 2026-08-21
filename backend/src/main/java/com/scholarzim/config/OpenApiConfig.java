package com.scholarzim.config;

import io.swagger.v3.oas.models.Components;
import io.swagger.v3.oas.models.OpenAPI;
import io.swagger.v3.oas.models.info.Contact;
import io.swagger.v3.oas.models.info.Info;
import io.swagger.v3.oas.models.security.SecurityRequirement;
import io.swagger.v3.oas.models.security.SecurityScheme;
import io.swagger.v3.oas.models.tags.Tag;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;

import java.util.List;


@Configuration
public class OpenApiConfig {

    @Bean
    public OpenAPI scholarZimOpenApi() {
        final String sessionScheme = "sessionCookie";
        return new OpenAPI()
                .info(new Info()
                        .title("ScholarZim API")
                        .description("Public and authenticated applicant REST endpoints for Zimbabwe's scholarship platform.")
                        .version("1.0")
                        .contact(new Contact().name("ScholarZim").url("https://scholarzim.co.zw")))
                .tags(List.of(
                        new Tag().name("Public").description("Unauthenticated platform data"),
                        new Tag().name("Applicant").description("Authenticated student endpoints")))
                .addSecurityItem(new SecurityRequirement().addList(sessionScheme))
                .components(new Components().addSecuritySchemes(sessionScheme,
                        new SecurityScheme()
                                .type(SecurityScheme.Type.APIKEY)
                                .in(SecurityScheme.In.COOKIE)
                                .name("JSESSIONID")
                                .description("Spring Security session cookie after login")));
    }
}
