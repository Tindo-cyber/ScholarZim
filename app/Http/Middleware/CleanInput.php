<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TransformsRequest;

/**
 * What every request carries, made safe to read before any rule sees it.
 *
 *   - A NUL byte ("\0") is removed from every string. It is never part of anything a person types, and it makes PHP functions
 *     that take dates, paths or SQL text throw instead of answering.
 *   - An email address is lower-cased (it is trimmed already, by TrimStrings). Addresses are compared as written, and on some
 *     databases "A@x.com" and "a@x.com" are two rows; keeping them lower case makes them one person everywhere.
 *
 * Only the top-level `email` field is lower-cased: a key inside an array, or any other field, is left as written.
 */
class CleanInput extends TransformsRequest
{
    protected function transform($key, $value)
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = str_replace("\0", '', $value);

        return $key === 'email' ? mb_strtolower($value) : $value;
    }
}
