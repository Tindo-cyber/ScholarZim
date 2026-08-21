package com.scholarzim.util;

import org.junit.jupiter.api.Test;

import java.util.List;

import static org.junit.jupiter.api.Assertions.assertEquals;

class ApplicationStatusTest {

    @Test
    void displayLabelReturnsFriendlyNames() {
        assertEquals("Submitted", ApplicationStatus.displayLabel(ApplicationStatus.SUBMITTED));
        assertEquals("Under review", ApplicationStatus.displayLabel(ApplicationStatus.UNDER_REVIEW));
        assertEquals("Approved", ApplicationStatus.displayLabel(ApplicationStatus.APPROVED));
    }

    @Test
    void displayLabelHandlesUnknownAndBlank() {
        assertEquals("Unknown", ApplicationStatus.displayLabel(null));
        assertEquals("Unknown", ApplicationStatus.displayLabel("  "));
        assertEquals("custom state", ApplicationStatus.displayLabel("CUSTOM_STATE"));
    }
}
