package com.scholarzim.service;

public interface TotpService {

    String generateSecret();

    String buildQrUri(String email, String secret);

    boolean verify(String secret, String code);

    void enableForUser(String email, String secret, String code);

    void disableForUser(String email);
}
