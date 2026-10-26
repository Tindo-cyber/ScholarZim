<?php

namespace Tests\Support;

use App\Models\ApplicantProfile;
use App\Models\User;

/**
 * Posts the applicant profile form the way a browser does: every field the page pre-fills (nested names such as
 * academic_subject_results[0][result] included), with only what a test means to change replaced.
 *
 * Needs $this->as(User) from the using test, which carries one person at a time through the role-isolated session.
 */
trait PostsProfileForm
{
    /** What the profile page would post for this applicant as it stands. @return array<string, mixed> */
    protected function profileFormFields(User $user): array
    {
        $html = $this->as($user)->get('/applicant/profile')->assertOk()->getContent();
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $pairs = [];

        foreach ($xpath->query('.//input|.//select|.//textarea', $xpath->query('//form[@id="sz-profile-form"]')->item(0)) as $element) {
            $name = $element->getAttribute('name');
            $type = strtolower($element->getAttribute('type'));

            if ($name === '' || $element->hasAttribute('disabled') || in_array($type, ['submit', 'button', 'file'], true)
                || (in_array($type, ['checkbox', 'radio'], true) && ! $element->hasAttribute('checked'))) {
                continue;
            }

            if ($element->tagName === 'select') {
                $value = '';
                foreach ($xpath->query('.//option', $element) as $option) {
                    if ($option->hasAttribute('selected')) {
                        $value = $option->getAttribute('value');
                    }
                }
            } else {
                $value = $element->tagName === 'textarea' ? $element->textContent : $element->getAttribute('value');
            }

            $pairs[] = rawurlencode($name) . '=' . rawurlencode($value);
        }

        parse_str(implode('&', $pairs), $fields);

        return $fields;
    }

    /** Saves the profile with some fields replaced. */
    protected function saveProfile(User $user, array $changes = [])
    {
        $fields = $this->profileFormFields($user);

        return $this->as($user)->from('/applicant/profile')->post('/applicant/profile', $changes + $fields);
    }

    protected function profileOf(User $user): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $user->user_id)->firstOrFail();
    }
}
