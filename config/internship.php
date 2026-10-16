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

];
