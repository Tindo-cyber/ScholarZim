package com.scholarzim.service;

import com.scholarzim.entity.User;


public interface EmailVerificationService {

    void issueVerificationToken(User user);

    void verify(String token);

    void resend(String email);
}
