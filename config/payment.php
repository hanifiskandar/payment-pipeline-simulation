<?php

return [
    'review_threshold' => (string) env('REVIEW_THRESHOLD', '500'),
    'supported_currencies' => explode(',', env('SUPPORTED_CURRENCIES', 'MYR,USD,SGD,EUR,GBP')),
    'log_verbosity' => env('LOG_VERBOSITY', 'normal'),
];
