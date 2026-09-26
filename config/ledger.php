<?php

return [
    'payout_thresholds' => [
        'EGP' => (int) env('PAYOUT_THRESHOLD_EGP', 0),
    ],
    'payout_destination' => ['token' => env('MOCK_PAYOUT_DESTINATION', 'dst_test')],
    'max_entries_per_payout' => 1000,
];
