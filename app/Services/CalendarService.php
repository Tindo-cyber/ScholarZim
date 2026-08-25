<?php

namespace App\Services;

use App\Models\Application;

/**
 * Builds the .ics file behind "Add to calendar" on a scheduled interview.
 *
 * Written by hand because the format is a dozen lines and a dependency would
 * outweigh it. Times are emitted in UTC (the trailing Z), which every calendar
 * client converts back to the reader's own zone - quoting Africa/Harare local
 * time without a zone is how an interview ends up an hour out.
 */
class CalendarService
{
    private const LINE_LIMIT = 75;

    public function interviewInvite(Application $application): string
    {
        $start = $application->interview_at?->copy()->utc();

        if ($start === null) {
            throw new \RuntimeException('This application has no interview scheduled.');
        }

        $end = $start->copy()->addHour();
        $title = $application->opportunity?->title ?? 'Scholarship';
        $provider = $application->opportunity?->awardingBody() ?? 'the provider';

        $description = 'Interview for the ' . $title . ' scholarship with ' . $provider . '. '
            . 'Bring your results certificate and identification.';

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//ScholarZim//Interview//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $this->uid($application),
            'DTSTAMP:' . now()->utc()->format('Ymd\THis\Z'),
            'DTSTART:' . $start->format('Ymd\THis\Z'),
            'DTEND:' . $end->format('Ymd\THis\Z'),
            'SUMMARY:' . $this->escape($title . ' interview'),
            'DESCRIPTION:' . $this->escape($description),
            'ORGANIZER;CN=' . $this->escape($provider) . ':MAILTO:'
                . ($application->opportunity?->provider?->email ?? config('mail.from.address')),
            'STATUS:CONFIRMED',
            // A day-before alarm, since the reminder job also mails one and the
            // two should agree.
            'BEGIN:VALARM',
            'TRIGGER:-P1D',
            'ACTION:DISPLAY',
            'DESCRIPTION:' . $this->escape($title . ' interview tomorrow'),
            'END:VALARM',
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", array_map(fn (string $line) => $this->fold($line), $lines)) . "\r\n";
    }

    public function filename(Application $application): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', (string) ($application->opportunity?->title ?? 'interview'));

        return trim(strtolower((string) $slug), '-') . '-interview.ics';
    }

    private function uid(Application $application): string
    {
        return 'scholarzim-application-' . $application->application_id . '@'
            . parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    /** Commas, semicolons, backslashes and newlines are structural in iCalendar. */
    private function escape(string $value): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n"],
            ['\\\\', '\\;', '\\,', '\\n', '\\n'],
            $value
        );
    }

    /** Content lines longer than 75 octets must be folded onto continuation lines. */
    private function fold(string $line): string
    {
        if (strlen($line) <= self::LINE_LIMIT) {
            return $line;
        }

        $folded = substr($line, 0, self::LINE_LIMIT);
        $rest = substr($line, self::LINE_LIMIT);

        foreach (str_split($rest, self::LINE_LIMIT - 1) as $chunk) {
            $folded .= "\r\n " . $chunk;
        }

        return $folded;
    }
}
