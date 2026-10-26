<?php

return [
    // Operator process-config stop; restart long-lived workers after changing it.
    // This cannot enable a provider, grant consent or cancel bytes already sent.
    'paused' => env('OPTIONAL_OPERATIONS_PAUSED', false) !== false,
];
