package com.scholarzim.config;

import org.springframework.boot.context.properties.ConfigurationProperties;
import org.springframework.context.annotation.Configuration;

import java.util.Map;


@Configuration
@ConfigurationProperties(prefix = "scholarzim.monetization")
public class MonetizationConfig {

    private Map<String, String> tiers = Map.of(
            "institutional", "Annual license per ministry/university",
            "provider", "Monthly subscription for posting and analytics",
            "featured", "Pay-per-campaign promoted listings",
            "premium_applicant", "Optional essay review and priority matching");

    public Map<String, String> getTiers() {
        return tiers;
    }

    public void setTiers(Map<String, String> tiers) {
        this.tiers = tiers;
    }
}
