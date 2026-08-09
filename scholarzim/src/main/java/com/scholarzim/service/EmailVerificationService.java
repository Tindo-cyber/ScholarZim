package com.scholarzim.service;

import com.scholarzim.entity.User;


public interface EmailVerificationService {

    void issueVerificationToken(User user);

    void verify(String token);

    void resend(String email);

    /** True when the account behind this (unconsumed) token has no password set yet — see verifyAndSetPassword. */
    boolean requiresPasswordSetup(String token);

    void verifyAndSetPassword(String token, String newPassword, String confirmPassword);
}
