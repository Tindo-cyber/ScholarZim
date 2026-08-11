package com.scholarzim.util;

import org.junit.jupiter.api.Test;

import java.time.LocalTime;
import java.time.ZoneId;
import java.time.ZonedDateTime;
import java.time.format.DateTimeFormatter;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;

class GreetingUtilTest {

    @Test
    void greetingBoundariesFollowZimbabweClock() {
        assertEquals("Good Morning", GreetingUtil.timeBasedGreeting(LocalTime.of(0, 0)));
        assertEquals("Good Morning", GreetingUtil.timeBasedGreeting(LocalTime.of(11, 59)));
        assertEquals("Good Afternoon", GreetingUtil.timeBasedGreeting(LocalTime.of(12, 0)));
        assertEquals("Good Afternoon", GreetingUtil.timeBasedGreeting(LocalTime.of(16, 59)));
        assertEquals("Good Evening", GreetingUtil.timeBasedGreeting(LocalTime.of(17, 0)));
        assertEquals("Good Evening", GreetingUtil.timeBasedGreeting(LocalTime.of(23, 59)));
    }

    @Test
    void currentGreetingUsesZimbabweTimeNotServerDefaultZone() {
        // Regression guard for the bug where the server's default zone (e.g. UTC
        // on Render) made the greeting lag behind Zimbabwe's actual wall clock.
        LocalTime harareNow = LocalTime.now(ZoneId.of("Africa/Harare"));
        assertEquals(GreetingUtil.timeBasedGreeting(harareNow), GreetingUtil.timeBasedGreeting());
    }

    @Test
    void todayLabelMatchesZimbabweCalendarDate() {
        String expected = ZonedDateTime.now(ZoneId.of("Africa/Harare"))
                .format(DateTimeFormatter.ofPattern("EEEE, d MMMM yyyy"));
        assertEquals(expected, GreetingUtil.todayLabel());
        assertTrue(GreetingUtil.todayLabel().matches("^[A-Za-z]+, \\d{1,2} [A-Za-z]+ \\d{4}$"));
    }
}
