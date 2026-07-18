package com.scholarzim.util;

import org.slf4j.Logger;

import java.util.function.Supplier;


public final class SoftLoad {

    private SoftLoad() {
    }

    public static <T> T of(Logger log, String label, T fallback, Supplier<T> supplier) {
        try {
            return supplier.get();
        } catch (Exception ex) {
            log.warn("{} failed: {}", label, ex.toString(), ex);
            return fallback;
        }
    }
}
