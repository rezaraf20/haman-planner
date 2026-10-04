<?php
declare(strict_types=1);

// User-facing error messages (API responses and the planner UI).
return [
    'server' => 'Something went wrong on our side and the request was not completed. Please try again. If it keeps happening, give support this code: :ref',
    'required_field' => 'Please fill in “:field”.',
    'invalid_data' => 'One of the values isn’t valid. Please check the fields.',
    'missing_reference' => 'The item you selected no longer exists. Refresh the page and choose again.',
    'duplicate' => 'A similar item already exists.',
    'not_found' => 'This item wasn’t found — it may have been deleted. Refresh the page.',
    'forbidden' => 'You don’t have permission to do this.',
    'unauthenticated' => 'Your session has ended. Please sign in again.',
    'expired' => 'This page has expired. Refresh it and try again.',
    'too_many' => 'Too many requests. Please wait a moment and try again.',
    'unavailable' => 'The service is temporarily unavailable. Please try again in a few minutes.',
    'network' => 'Couldn’t reach the server. Check your connection and try again.',
    'fix_fields' => 'Please correct the highlighted fields.',
];
