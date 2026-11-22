<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default required hours
    |--------------------------------------------------------------------------
    |
    | The number of internship hours an intern is expected to render by
    | default (based on the standard DOLE GIP requirement). This can be
    | overridden per-intern on the intern record itself.
    |
    */
    'default_required_hours' => env('INTERNSHIP_DEFAULT_REQUIRED_HOURS', 486),

    /*
    |--------------------------------------------------------------------------
    | Default hours per working day
    |--------------------------------------------------------------------------
    |
    | Used to convert remaining hours to an estimated number of working days
    | when an intern has no per-intern override. Admins can edit the value on
    | each intern record.
    |
    */
    'hours_per_workday' => (float) env('INTERNSHIP_HOURS_PER_WORKDAY', 8),

    /*
    |--------------------------------------------------------------------------
    | Completion threshold (percent)
    |--------------------------------------------------------------------------
    |
    | When an intern's rendered hours reach this percentage of their required
    | hours, the `interns:check-completion` command will flag them as
    | completed and notify the PESO team.
    |
    */
    'completion_threshold' => env('INTERNSHIP_COMPLETION_THRESHOLD', 90),

    /*
    |--------------------------------------------------------------------------
    | Contract expiry warning window (days)
    |--------------------------------------------------------------------------
    |
    | How many days before a contract end date to raise an expiry reminder.
    |
    */
    'contract_expiry_warning_days' => env('INTERNSHIP_CONTRACT_EXPIRY_WARNING_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Public intern self-registration
    |--------------------------------------------------------------------------
    |
    | A shared registration link lets applicants submit an application without
    | logging in. The link is an unguessable token embedded in the SPA URL;
    | approval is still required before an intern/user account is created.
    |
    */
    'registration' => [
        // Public frontend origin used to build the shareable link / QR code.
        'frontend_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost:3000')),

        // Default lifetime of a generated link (days). Admins may override.
        'link_ttl_days' => (int) env('INTERNSHIP_REGISTRATION_LINK_TTL_DAYS', 30),

        // Spam protection for public submissions (per IP).
        'max_submissions_per_ip' => (int) env('INTERNSHIP_REGISTRATION_MAX_SUBMISSIONS', 5),
        'submission_window_minutes' => (int) env('INTERNSHIP_REGISTRATION_WINDOW_MINUTES', 10),
    ],

];
