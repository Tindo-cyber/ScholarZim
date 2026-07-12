package com.scholarzim.util;

import java.util.List;


public final class NotificationType {

    private NotificationType() {
    }

    public static final String APPLICATION_APPROVED = "APPLICATION_APPROVED";
    public static final String APPLICATION_REJECTED = "APPLICATION_REJECTED";
    public static final String APPLICATION_SUBMITTED = "APPLICATION_SUBMITTED";
    public static final String NEW_APPLICATION = "NEW_APPLICATION";
    public static final String DOCUMENTS_REQUESTED = "DOCUMENTS_REQUESTED";
    public static final String NEW_OPPORTUNITY = "NEW_OPPORTUNITY";
    public static final String DEADLINE_REMINDER = "DEADLINE_REMINDER";
    public static final String PROFILE_INCOMPLETE = "PROFILE_INCOMPLETE";
    public static final String PROVIDER_APPLICATION = "PROVIDER_APPLICATION";
    public static final String PROVIDER_APPROVED = "PROVIDER_APPROVED";
    public static final String PROVIDER_REJECTED = "PROVIDER_REJECTED";
    public static final String MESSAGE_RECEIVED = "MESSAGE_RECEIVED";

    public static final List<String> ALL = List.of(
            APPLICATION_SUBMITTED,
            NEW_APPLICATION,
            APPLICATION_APPROVED,
            APPLICATION_REJECTED,
            DOCUMENTS_REQUESTED,
            DEADLINE_REMINDER,
            NEW_OPPORTUNITY,
            PROFILE_INCOMPLETE,
            MESSAGE_RECEIVED,
            PROVIDER_APPLICATION,
            PROVIDER_APPROVED,
            PROVIDER_REJECTED);
}
