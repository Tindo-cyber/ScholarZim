package com.scholarzim.util;

import java.time.LocalTime;


public final class GreetingUtil {

    private GreetingUtil() {
    }

    public static String timeBasedGreeting() {
        int hour = LocalTime.now().getHour();
        if (hour < 12) {
            return "Good Morning";
        }
        if (hour < 17) {
            return "Good Afternoon";
        }
        return "Good Evening";
    }
}
