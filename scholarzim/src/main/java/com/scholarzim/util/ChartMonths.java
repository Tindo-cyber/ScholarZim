package com.scholarzim.util;

import java.time.YearMonth;
import java.time.format.DateTimeFormatter;
import java.util.ArrayList;
import java.util.List;


public final class ChartMonths {

    private static final DateTimeFormatter LABEL_FORMAT = DateTimeFormatter.ofPattern("MMM yyyy");

    private ChartMonths() {
    }

    /**
     * Labels for the last {@code months} calendar months, oldest to newest.
     */
    public static List<String> labelsForLastMonths(int months) {

        List<String> labels = new ArrayList<>(months);
        YearMonth now = YearMonth.now();

        for (int i = months - 1; i >= 0; i--) {
            labels.add(now.minusMonths(i).format(LABEL_FORMAT));
        }

        return labels;
    }
}
