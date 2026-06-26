package com.scholarzim.service.impl;

import com.scholarzim.service.SmsService;
import lombok.extern.slf4j.Slf4j;
import org.springframework.stereotype.Service;

@Slf4j
@Service
public class SmsServiceImpl implements SmsService {

    @Override
    public void sendDeadlineReminder(String phone, String message) {
        if (phone == null || phone.isBlank()) {
            return;
        }
        log.info("SMS reminder to {}: {}", phone, message);
    }
}
