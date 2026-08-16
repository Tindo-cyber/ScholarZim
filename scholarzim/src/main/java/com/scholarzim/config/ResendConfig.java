package com.scholarzim.config;

import com.resend.Resend;
import lombok.extern.slf4j.Slf4j;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.context.annotation.Profile;

@Slf4j
@Configuration
@Profile("prod")
public class ResendConfig {

    @Bean
    public Resend resend(@Value("${resend.api-key:}") String apiKey) {
        if (apiKey == null || apiKey.isBlank()) {
            log.warn("RESEND_API_KEY is not set — outbound email will fail until it is configured.");
        }
        return new Resend(apiKey == null ? "" : apiKey);
    }
}
