<?php

return [
    'regular_min_orders' => env('CUSTOMER_CRM_REGULAR_MIN_ORDERS', 5),
    'regular_period_days' => env('CUSTOMER_CRM_REGULAR_PERIOD_DAYS', 90),
    'inactive_days' => env('CUSTOMER_CRM_INACTIVE_DAYS', 180),
];
