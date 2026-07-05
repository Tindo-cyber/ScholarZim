package com.scholarzim.util;

import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;

class NotificationPresentationTest {

    @Test
    void mapsKnownTypes() {
        assertEquals("bi-check-circle-fill",
                NotificationPresentation.icon(NotificationType.APPLICATION_APPROVED));
        assertEquals("success",
                NotificationPresentation.tone(NotificationType.APPLICATION_APPROVED));
        assertEquals("Approved",
                NotificationPresentation.label(NotificationType.APPLICATION_APPROVED));
    }

    @Test
    void fallsBackForUnknownType() {
        assertEquals("bi-bell-fill", NotificationPresentation.icon("UNKNOWN"));
        assertEquals("primary", NotificationPresentation.tone("UNKNOWN"));
    }
}
