<?php

namespace App\Services;

use App\Models\User;
use App\Support\AwardSanity;

/**
 * Reasons a moderator should look at a listing twice.
 *
 * This is the home of every such check. A listing that raises any reason is
 * reviewed before it goes live, whoever posted it - a trusted provider's
 * shortcut (see ProviderTrust) does not apply to it.
 *
 * It only reports. A flag never blocks a save and never decides anything: the
 * reasons are stored on the listing (opportunities.risk_flags) and shown to the
 * administrator reviewing it, who decides. Each reason is a {code, message}: the
 * code is for code (filtering, tests), the message is written for the moderator.
 *
 * WHAT COUNTS AS ASKING FOR MONEY
 *
 * The signal that matters is an applicant being asked to pay, not a listing
 * mentioning fees: "tuition fees covered" and "fees paid directly to the
 * institution" are what a good scholarship says, and a checker that flagged them
 * would flag every honest listing and so teach moderators to ignore it. So the
 * description is read a sentence at a time, and a sentence is judged by who is
 * paying:
 *
 *   1. it says nobody pays ("no application fee", "pay nothing")  -> not flagged
 *   2. it tells the applicant they must pay money                  -> flagged
 *   3. it says the award or sponsor pays ("covers", "paid directly") -> not flagged
 *   4. otherwise a named fee (registration fee, processing fee), a deposit, or a
 *      mobile-money or crypto channel asked for                    -> flagged
 *
 * The order is the point: step 2 comes before step 3 so "applicants must pay a
 * registration fee, tuition is covered" is caught by its first half rather than
 * excused by its second. The patterns are deliberately plain and readable; they
 * will miss a determined scammer, and they are not trying to stop one - they put
 * the obvious cases in front of a person.
 */
class ListingRiskChecker
{
    public const AWARD_ABOVE_CEILING = 'award_above_ceiling';

    public const ON_BEHALF_OF = 'on_behalf_of';

    public const ASKS_FOR_PAYMENT = 'asks_for_payment';

    public const PHONE_ONLY_CONTACT = 'phone_only_contact';

    public const URL_DOMAIN_MISMATCH = 'url_domain_mismatch';

    /** Set by the report process, not by this checker; kept across edits (see stickyFlags). */
    public const REPORTED = 'reported';

    /** Email providers anyone can sign up to: an address there says nothing about who the provider is. */
    private const FREE_EMAIL_DOMAINS = [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk', 'outlook.com', 'hotmail.com',
        'live.com', 'msn.com', 'icloud.com', 'me.com', 'aol.com', 'proton.me', 'protonmail.com',
        'mail.com', 'gmx.com', 'yandex.com', 'zoho.com',
    ];

    /**
     * Flags that belong to something other than the listing's own text, so
     * recomputing a listing's flags on an edit must not drop them.
     *
     * @return array<int, string>
     */
    public static function stickyFlags(): array
    {
        return [self::REPORTED];
    }

    /**
     * @param  array<string, mixed>  $listing  the attributes about to be saved
     * @param  User|null  $provider  who is posting, for checks that compare the listing with them
     * @return array<int, array{code: string, message: string}>
     */
    public function check(array $listing, ?User $provider = null): array
    {
        $flags = [];

        $text = trim((string) ($listing['title'] ?? '') . '. ' . (string) ($listing['description'] ?? ''));

        if ($sentence = $this->askForPayment($text)) {
            $flags[] = [
                'code' => self::ASKS_FOR_PAYMENT,
                'message' => 'The description seems to ask applicants for money: "' . $this->shorten($sentence) . '". A genuine scholarship does not charge to apply.',
            ];
        }

        if ($this->phoneOnlyContact($text, $listing['external_url'] ?? null)) {
            $flags[] = [
                'code' => self::PHONE_ONLY_CONTACT,
                'message' => 'The only way to reach the provider is a phone number or WhatsApp - no email address and no link.',
            ];
        }

        if ($problem = $this->urlProblem($listing['external_url'] ?? null, $provider)) {
            $flags[] = ['code' => self::URL_DOMAIN_MISMATCH, 'message' => $problem];
        }

        $amount = filled($listing['award_amount'] ?? null) ? (float) $listing['award_amount'] : null;

        if ($reason = AwardSanity::flagReason($amount, $listing['award_currency'] ?? null)) {
            $flags[] = ['code' => self::AWARD_ABOVE_CEILING, 'message' => $reason];
        }

        if (filled($listing['on_behalf_of'] ?? null)) {
            $flags[] = [
                'code' => self::ON_BEHALF_OF,
                'message' => 'Posted on behalf of "' . $listing['on_behalf_of']
                    . '". Check the provider is authorised to award in that name.',
            ];
        }

        return $flags;
    }

    /**
     * Keep the flags this checker does not own when a listing's own are
     * recomputed.
     *
     * @param  array<int, array{code: string, message: string}>|null  $existing
     * @param  array<int, array{code: string, message: string}>  $fresh
     * @return array<int, array{code: string, message: string}>
     */
    public function withStickyFlags(?array $existing, array $fresh): array
    {
        $kept = array_values(array_filter(
            (array) $existing,
            fn ($flag) => in_array($flag['code'] ?? null, self::stickyFlags(), true)
        ));

        return array_merge($fresh, $kept);
    }

    // ----------------------------------------------------------- payment --

    /** The first sentence that asks the applicant to pay, or null. */
    private function askForPayment(string $text): ?string
    {
        foreach ($this->sentences($text) as $sentence) {
            if ($this->nobodyPays($sentence)) {
                continue;
            }

            if ($this->tellsApplicantToPay($sentence)) {
                return $sentence;
            }

            if ($this->saysSomeoneElsePays($sentence)) {
                continue;
            }

            if ($this->namesAFeeOrChannel($sentence)) {
                return $sentence;
            }
        }

        return null;
    }

    /** @return array<int, string> */
    private function sentences(string $text): array
    {
        // Also split on "but" / "however": "tuition is covered, but you must pay a
        // registration fee" is two statements, and the first must not excuse the second.
        $parts = preg_split('/(?<=[.!?;])\s+|\R+|\s+(?:but|however|although|though|except)\s+/i', $text) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn ($part) => $part !== ''));
    }

    private function nobodyPays(string $sentence): bool
    {
        return (bool) preg_match(
            '/\b(?:no|zero|without|free of|free from|never charges?|not charged?|not required to pay|nothing)\b[^.!?;]{0,40}?\b(?:fees?|charges?|payments?|costs?|deposits?|pay)\b/i',
            $sentence
        ) || (bool) preg_match('/\bpay(?:s|ing)?\s+(?:nothing|no\b|zero)/i', $sentence);
    }

    private function tellsApplicantToPay(string $sentence): bool
    {
        return (bool) preg_match(
            '/\b(?:you|applicants?|candidates?|students?|successful (?:applicants?|candidates?))\b[^.!?;]{0,40}?\b(?:must|need to|needs to|have to|has to|will need to|will have to|should|will|shall|are required to|is required to|will be required to|are expected to)\s+(?:first\s+|also\s+)?(?:pay|send|deposit|transfer|remit)\b[^.!?;]{0,60}?(?:\bfees?\b|\bmoney\b|\bpayments?\b|\bdeposit\b|\bcharges?\b|\bamount\b|\$|\busd\b|\bzwg\b|\bzar\b|\d)/i',
            $sentence
        );
    }

    private function saysSomeoneElsePays(string $sentence): bool
    {
        $coverage = (bool) preg_match(
            '/\b(?:covers?|covered|covering|pays|paid|funds?|funded|funding|sponsors?|sponsored|waives?|waived|subsidi[sz](?:es|ed)?|includes?|included|reimburs(?:es|ed))\b/i',
            $sentence
        ) || (bool) preg_match(
            // "will pay" is coverage only when the one paying is the award's side:
            // "the sponsor will pay tuition" is, "you will pay a fee" is not.
            '/\b(?:sponsors?|providers?|scholarship|award|foundation|trust|funders?|we|government|company|employer|organi[sz]ation|university|college|school|ministry|programme|program)\b[^.!?;]{0,25}\b(?:will|shall|would)\s+pay\b/i',
            $sentence
        );

        // "paid by the applicant" is coverage by the wrong party.
        $byApplicant = (bool) preg_match('/\b(?:by|from)\s+(?:the\s+)?(?:applicants?|students?|candidates?|you)\b/i', $sentence);

        return $coverage && ! $byApplicant;
    }

    private function namesAFeeOrChannel(string $sentence): bool
    {
        if (preg_match('/\b(?:application|processing|registration|administration|administrative|handling|verification|screening|clearance|membership|booking|courier|document|visa|service)\s+(?:fees?|charges?)\b/i', $sentence)) {
            return true;
        }

        if (preg_match('/\b(?:pay|paying|payment of|send|sending|deposit|transfer)\b[^.!?;]{0,40}?\b(?:fees?|charges?)\b/i', $sentence)) {
            return true;
        }

        if (preg_match('/\b(?:refundable\s+)?(?:security\s+)?deposit\b/i', $sentence)) {
            return true;
        }

        // A money channel only matters when the sentence is asking for money to be sent.
        if (preg_match('/\b(?:eco\s?cash|inn\s?bucks|one\s?money|omari|mukuru|western union|money\s?gram|pay\s?now|m-?pesa|bitcoin|crypto(?:currency)?|gift cards?)\b/i', $sentence)
            && preg_match('/\b(?:send|pay|deposit|transfer|payable|remit)\b/i', $sentence)) {
            return true;
        }

        return false;
    }

    // ----------------------------------------------------------- contact --

    private function phoneOnlyContact(string $text, mixed $externalUrl): bool
    {
        $mentionsPhone = (bool) preg_match('/whats\s?app/i', $text)
            || (bool) preg_match('/(?:\+\d{1,3}[\s\-]?|\b0)\d{1,3}[\s\-]?\d{3}[\s\-]?\d{3,4}\b/', $text);

        if (! $mentionsPhone) {
            return false;
        }

        $hasEmail = (bool) preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text);
        $hasLink = filled($externalUrl) || (bool) preg_match('~https?://|\bwww\.~i', $text);

        return ! $hasEmail && ! $hasLink;
    }

    // --------------------------------------------------------------- url --

    private function urlProblem(mixed $url, ?User $provider): ?string
    {
        if (! filled($url) || $provider === null) {
            return null;
        }

        $host = $this->host((string) $url);

        if ($host === null) {
            return null;
        }

        // A website an administrator has confirmed as the organisation's own is the
        // best evidence of who they are, and replaces the email domain rather than
        // adding to it. One that has only been typed in is not evidence, so it is
        // ignored and the email domain is used as before.
        $confirmed = $provider->providerProfile?->confirmedWebsiteHost();

        if ($confirmed !== null) {
            if ($host === $confirmed || str_ends_with($host, '.' . $confirmed)) {
                return null;
            }

            return 'The application link (' . $host . ') is not on the provider\'s confirmed website (' . $confirmed . ').';
        }

        $domain = $this->emailDomain($provider->email);

        if ($domain === null || in_array($domain, self::FREE_EMAIL_DOMAINS, true)) {
            return 'The application link (' . $host . ') cannot be matched to the provider: they registered with '
                . ($domain ?? 'no email') . ', which does not identify an organisation.';
        }

        if ($host === $domain || str_ends_with($host, '.' . $domain)) {
            return null;
        }

        return 'The application link (' . $host . ') is not on the provider\'s own domain (' . $domain . ').';
    }

    private function host(string $url): ?string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return preg_replace('/^www\./', '', strtolower($host));
    }

    private function emailDomain(?string $email): ?string
    {
        if (! is_string($email) || ! str_contains($email, '@')) {
            return null;
        }

        return strtolower(substr(strrchr($email, '@'), 1)) ?: null;
    }

    private function shorten(string $sentence): string
    {
        return mb_strlen($sentence) > 140 ? mb_substr($sentence, 0, 137) . '...' : $sentence;
    }
}
