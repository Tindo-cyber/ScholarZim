package com.scholarzim.service;

public interface SmsService {

    void sendDeadlineReminder(String phone, String message);
}
