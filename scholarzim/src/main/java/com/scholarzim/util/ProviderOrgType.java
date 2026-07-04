package com.scholarzim.util;

import java.util.List;
import java.util.Map;


public final class ProviderOrgType {

    public static final String NGO = "NGO";
    public static final String PRIVATE_COMPANY = "PRIVATE_COMPANY";
    public static final String FOUNDATION = "FOUNDATION";
    public static final String GOVERNMENT = "GOVERNMENT";
    public static final String UNIVERSITY = "UNIVERSITY";
    public static final String OTHER = "OTHER";

    public static final List<String> ALL = List.of(
            NGO, PRIVATE_COMPANY, FOUNDATION, GOVERNMENT, UNIVERSITY, OTHER);

    private static final Map<String, String> LABELS = Map.of(
            NGO, "NGO / Non-profit",
            PRIVATE_COMPANY, "Private company",
            FOUNDATION, "Foundation / Trust",
            GOVERNMENT, "Government body",
            UNIVERSITY, "University / College",
            OTHER, "Other registered entity");

    private ProviderOrgType() {
    }

    public static boolean isValid(String value) {
        return value != null && ALL.contains(value);
    }

    public static String label(String code) {
        return LABELS.getOrDefault(code, code);
    }
}
