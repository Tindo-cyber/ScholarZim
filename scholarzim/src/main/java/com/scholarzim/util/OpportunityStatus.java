package com.scholarzim.util;

import org.springframework.util.StringUtils;


public final class OpportunityStatus {

    public static final String ACTIVE = "ACTIVE";

    private OpportunityStatus() {
    }

    public static String displayLabel(String status) {
        if (!StringUtils.hasText(status)) {
            return "Unknown";
        }
        return ACTIVE.equalsIgnoreCase(status) ? "Open" : status;
    }
}
