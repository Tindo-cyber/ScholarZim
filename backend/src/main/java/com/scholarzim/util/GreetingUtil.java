package com.scholarzim.util;

import java.time.LocalDate;
import java.time.LocalTime;
import java.time.ZoneId;
import java.time.format.DateTimeFormatter;
import java.util.Locale;


public final class GreetingUtil {

    /** ScholarZim only serves Zimbabwe, so "now"/"today" are computed in the
     *  user's timezone rather than the server's — Render's containers
     *  default to UTC, which made "Good Morning" linger into the Zimbabwe
     *  afternoon (and could show the wrong calendar date near midnight). */
    private static final ZoneId ZIMBABWE = ZoneId.of("Africa/Harare");
    private static final DateTimeFormatter TODAY_FORMAT =
            DateTimeFormatter.ofPattern("EEEE, d MMMM yyyy", Locale.ENGLISH);

    private GreetingUtil() {
    }

    public static String timeBasedGreeting() {
        return timeBasedGreeting(LocalTime.now(ZIMBABWE));
    }

    static String timeBasedGreeting(LocalTime time) {
        int hour = time.getHour();
        if (hour < 12) {
            return "Good Morning";
        }
        if (hour < 17) {
            return "Good Afternoon";
        }
        return "Good Evening";
    }

    public static String todayLabel() {
        return LocalDate.now(ZIMBABWE).format(TODAY_FORMAT);
    }
}
