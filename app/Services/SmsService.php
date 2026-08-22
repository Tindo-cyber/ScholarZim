<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Deadline reminders by SMS. No gateway is wired up yet — as in the Spring app,
 * the message is logged so the delivery path is exercised end to end and can be
 * pointed at a real provider without touching the callers.
 */
class SmsService
{
    public function sendDeadlineReminder(?string $phone, string $message): void
    {
        if (blank($phone)) {
            return;
        }

        Log::info('SMS reminder', ['phone' => $phone, 'message' => $message]);
    }
}
