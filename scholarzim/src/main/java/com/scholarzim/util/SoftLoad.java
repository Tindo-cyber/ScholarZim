package com.scholarzim.util;

import org.slf4j.Logger;

import java.util.function.Supplier;
import java.util.concurrent.atomic.AtomicBoolean;


public final class SoftLoad {

    private SoftLoad() {
    }

    public static <T> T of(Logger log, String label, T fallback, Supplier<T> supplier) {
        try {
            return supplier.get();
        } catch (Exception ex) {
            log.warn("{} failed: {}", label, ex.getMessage());
            return fallback;
        }
    }

    public static <T> T of(Logger log, String label, T fallback, Supplier<T> supplier, AtomicBoolean failed) {
        try {
            return supplier.get();
        } catch (Exception ex) {
            log.warn("{} failed: {}", label, ex.getMessage());
            if (failed != null) {
                failed.set(true);
            }
            // #region agent log
            com.scholarzim.debug.AgentDebugLog.log("B", "SoftLoad.of", "softload_fallback",
                    java.util.Map.of(
                            "label", String.valueOf(label),
                            "exClass", ex.getClass().getName(),
                            "exMessage", String.valueOf(ex.getMessage())));
            // #endregion
            return fallback;
        }
    }
}
