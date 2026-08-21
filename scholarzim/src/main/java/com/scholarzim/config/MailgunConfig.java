package com.scholarzim.config;

import lombok.extern.slf4j.Slf4j;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.context.annotation.Profile;
import org.springframework.web.client.RestClient;

@Slf4j
@Configuration
@Profile("prod")
public class MailgunConfig {

    @Bean
    public RestClient mailgunClient(
            RestClient.Builder builder,
            @Value("${scholarzim.mailgun.api-key:}") String apiKey) {

        if (apiKey.isBlank()) {
            log.warn("MAILGUN_API_KEY is not set — outbound email will fail until it is configured.");
        }

        return builder
                .baseUrl("https://api.mailgun.net/v3")
                .requestInterceptor((request, body, execution) -> {
                    request.getHeaders().setBasicAuth("api", apiKey);
                    return execution.execute(request, body);
                })
                .build();
    }
}
