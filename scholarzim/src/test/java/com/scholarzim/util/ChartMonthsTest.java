package com.scholarzim.util;

import org.junit.jupiter.api.Test;

import java.time.YearMonth;
import java.time.format.DateTimeFormatter;
import java.util.List;

import static org.junit.jupiter.api.Assertions.assertEquals;

class ChartMonthsTest {

    private static final DateTimeFormatter FORMAT = DateTimeFormatter.ofPattern("MMM yyyy");

    @Test
    void labelsForLastMonthsReturnsOldestToNewest() {
        YearMonth now = YearMonth.now();
        List<String> labels = ChartMonths.labelsForLastMonths(3);

        assertEquals(3, labels.size());
        assertEquals(now.minusMonths(2).format(FORMAT), labels.get(0));
        assertEquals(now.minusMonths(1).format(FORMAT), labels.get(1));
        assertEquals(now.format(FORMAT), labels.get(2));
    }
}
