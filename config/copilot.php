<?php

return [
    'enabled' => env('COPILOT_ENABLED', false) === true,
    // Optional answer transport; no key is committed or available to ordinary CI.
    'answer_enabled' => env('COPILOT_ANSWER_ENABLED', false) === true,
    'answer_model' => 'gpt-5.4-2026-03-05',
    'openai_key' => env('OPENAI_API_KEY'),
    'requests_per_day' => 10,
    'reserved_tokens_per_day' => 65536,
    'tokens_per_request' => 16384,
    'input_bytes' => 2048,
    'provider_output_bytes' => 2048,
    'evidence_bytes' => 8192,
    'retained_requests' => 512,
];
