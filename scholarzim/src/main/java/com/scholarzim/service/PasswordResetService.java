package com.scholarzim.service;

public interface PasswordResetService {

    void requestReset(String email);

    void resetPassword(String token, String newPassword, String confirmPassword);
}
