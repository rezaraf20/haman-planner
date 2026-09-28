<?php
declare(strict_types=1);

return [
    // Recurring tasks: occurrences are created at most this many days ahead (generated lazily).
    'recurrence_horizon_days' => (int) env('PLANNER_RECURRENCE_HORIZON_DAYS', 14),

    // Plan proposals (smart rescheduling / Haman AI) expire if not applied.
    'proposal_ttl_hours' => (int) env('PLANNER_PROPOSAL_TTL_HOURS', 24),

    // Minimum samples before an insight is shown ("Not enough history yet" otherwise).
    'insights_min_samples' => (int) env('PLANNER_INSIGHTS_MIN_SAMPLES', 5),

    // Task / project attachments (private disk; never served from /public).
    'attachments' => [
        'disk' => env('ATTACHMENTS_DISK', 'attachments'),
        'max_kb' => (int) env('ATTACHMENTS_MAX_KB', 10240),
        // extension => allowed detected MIME types (content-sniffed, the client MIME is ignored)
        'types' => [
            'pdf' => ['application/pdf'],
            'png' => ['image/png'],
            'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'txt' => ['text/plain'], 'md' => ['text/plain', 'text/markdown'], 'csv' => ['text/plain', 'text/csv', 'application/csv'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
            'zip' => ['application/zip'],
        ],
    ],
];
