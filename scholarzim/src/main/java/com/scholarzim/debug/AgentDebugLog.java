package com.scholarzim.debug;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.StandardOpenOption;
import java.time.Instant;
import java.util.Map;
import java.util.stream.Collectors;

/**
 * Session debug NDJSON logger (temporary).
 * Writes local NDJSON when possible; always emits SLF4J for Render logs.
 */
public final class AgentDebugLog {

    private static final Logger LOG_SLF4J = LoggerFactory.getLogger("AGENT_DEBUG");
    private static final Path LOG = Path.of("C:/Users/Tatenda/Documents/ScholarZim/debug-7aba56.log");

    private AgentDebugLog() {
    }

    public static void log(String hypothesisId, String location, String message, Map<String, ?> data) {
        // #region agent log
        try {
            String dataJson = data == null || data.isEmpty()
                    ? "{}"
                    : data.entrySet().stream()
                    .map(e -> "\"" + escape(e.getKey()) + "\":" + toJsonValue(e.getValue()))
                    .collect(Collectors.joining(",", "{", "}"));
            String line = "{"
                    + "\"sessionId\":\"7aba56\","
                    + "\"hypothesisId\":\"" + escape(hypothesisId) + "\","
                    + "\"location\":\"" + escape(location) + "\","
                    + "\"message\":\"" + escape(message) + "\","
                    + "\"data\":" + dataJson + ","
                    + "\"timestamp\":" + Instant.now().toEpochMilli()
                    + "}";
            LOG_SLF4J.warn("AGENT_DEBUG {}", line);
            Files.writeString(LOG, line + "\n", StandardCharsets.UTF_8,
                    StandardOpenOption.CREATE, StandardOpenOption.APPEND);
        } catch (Exception ignored) {
            // never break app flow
        }
        // #endregion
    }

    private static String toJsonValue(Object value) {
        if (value == null) {
            return "null";
        }
        if (value instanceof Number || value instanceof Boolean) {
            return String.valueOf(value);
        }
        return "\"" + escape(String.valueOf(value)) + "\"";
    }

    private static String escape(String s) {
        if (s == null) {
            return "";
        }
        return s.replace("\\", "\\\\").replace("\"", "\\\"").replace("\n", "\\n").replace("\r", "");
    }
}
