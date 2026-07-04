package com.scholarzim.util;

public final class AuditAction {

    private AuditAction() {
    }

    public static final String REGISTER = "REGISTER";
    public static final String APPLY = "APPLY";
    public static final String STATUS_UPDATE = "STATUS_UPDATE";
    public static final String CREATE_OPPORTUNITY = "CREATE_OPPORTUNITY";
    public static final String DELETE_USER = "DELETE_USER";
    public static final String UPDATE_USER = "UPDATE_USER";
    public static final String VIEW_PROVIDER_CERTIFICATE = "VIEW_PROVIDER_CERTIFICATE";
    public static final String REJECT_PROVIDER = "REJECT_PROVIDER";
    public static final String VIEW_APPLICANT_RESULTS = "VIEW_APPLICANT_RESULTS";
    public static final String LOGIN_SUCCESS = "LOGIN_SUCCESS";
    public static final String LOGIN_FAILURE = "LOGIN_FAILURE";
    public static final String PASSWORD_RESET_REQUEST = "PASSWORD_RESET_REQUEST";
    public static final String PASSWORD_RESET_COMPLETE = "PASSWORD_RESET_COMPLETE";
}
