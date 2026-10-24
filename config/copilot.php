<?php

return [
    'enabled' => env('COPILOT_ENABLED', false) === true,
    // No live provider adapter or credential is selected in M7-P1.
    'requests_per_day' => 10,
    'reserved_tokens_per_day' => 65536,
    'tokens_per_request' => 16384,
    'input_bytes' => 2048,
    'provider_output_bytes' => 2048,
    'evidence_bytes' => 8192,
    'retained_requests' => 512,
];
