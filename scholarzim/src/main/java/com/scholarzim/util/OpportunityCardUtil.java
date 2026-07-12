package com.scholarzim.util;

import com.scholarzim.entity.Opportunity;
import org.springframework.util.StringUtils;


public final class OpportunityCardUtil {

    private OpportunityCardUtil() {
    }

    public static String logoInitials(String providerName) {
        if (!StringUtils.hasText(providerName)) {
            return "SZ";
        }
        String trimmed = providerName.trim();
        String[] words = trimmed.split("\\s+");
        if (words.length >= 2) {
            return (String.valueOf(words[0].charAt(0)) + words[1].charAt(0)).toUpperCase();
        }
        return trimmed.length() >= 2
                ? trimmed.substring(0, 2).toUpperCase()
                : trimmed.toUpperCase();
    }

    public static String fieldOfStudy(Opportunity opportunity) {
        if (opportunity == null) {
            return "Any field";
        }
        if (StringUtils.hasText(opportunity.getTargetField())) {
            return opportunity.getTargetField();
        }
        return "Any field";
    }

    public static String fundingAmount(Opportunity opportunity) {
        if (opportunity == null || !StringUtils.hasText(opportunity.getFundingType())) {
            return "Funding details available";
        }
        return opportunity.getFundingType();
    }

    public static String eligibilitySummary(Opportunity opportunity) {
        if (opportunity == null) {
            return "Open to eligible applicants.";
        }
        StringBuilder summary = new StringBuilder();
        if (StringUtils.hasText(opportunity.getEducationLevel())) {
            summary.append(opportunity.getEducationLevel()).append(" level");
        }
        if (StringUtils.hasText(opportunity.getTargetField())) {
            if (!summary.isEmpty()) {
                summary.append(" · ");
            }
            summary.append(opportunity.getTargetField());
        } else if (StringUtils.hasText(opportunity.getTargetCountry())) {
            if (!summary.isEmpty()) {
                summary.append(" · ");
            }
            summary.append(opportunity.getTargetCountry());
        }
        if (StringUtils.hasText(opportunity.getCountry())) {
            if (!summary.isEmpty()) {
                summary.append(" · ");
            }
            summary.append(opportunity.getCountry());
        }
        if (!summary.isEmpty()) {
            return summary.toString();
        }
        if (StringUtils.hasText(opportunity.getDescription())) {
            String desc = opportunity.getDescription().trim();
            return desc.length() > 100 ? desc.substring(0, 97) + "..." : desc;
        }
        return "Open to eligible applicants.";
    }
}
