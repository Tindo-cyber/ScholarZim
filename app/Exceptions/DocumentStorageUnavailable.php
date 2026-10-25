<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An application could not be checked or sent because document storage could not be asked.
 *
 * Deliberately not DocumentsRequired: nothing is known to be wrong with the applicant's documents, only that the store
 * holding them did not answer, so telling them to upload again would be a guess that could cost them a good file. The
 * application is refused so that nothing is submitted on an unconfirmed requirement, and the applicant is asked to try
 * again. A RuntimeException like the other business-rule refusals, so any caller that reports those still reports this.
 */
class DocumentStorageUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'We could not check your documents just now because document storage is not responding. '
            . 'Nothing was submitted and your documents have not been changed. Please try again in a few minutes.'
        );
    }
}
