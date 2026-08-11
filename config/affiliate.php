<?php

return [
    /*
     * P6-D1 (D-058). Fail-closed: with the flag absent the governance surface refuses,
     * because an affiliate programme nobody switched on must not look switched on.
     *
     * There is deliberately nothing else here. No marketing flag, no public programme
     * announcement, no rate: every tunable lives in `affiliate_program_policies`,
     * versioned, and is changed by publishing a new version — never by editing config.
     */
    'governance_enabled' => env('AFFILIATE_GOVERNANCE_ENABLED', false),
];
