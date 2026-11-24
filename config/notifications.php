<?php

return [
    'outbox_redispatch_after_seconds' => (int) env('NOTIFICATION_OUTBOX_REDISPATCH_AFTER_SECONDS', 300),
    'outbox_dispatch_batch' => (int) env('NOTIFICATION_OUTBOX_DISPATCH_BATCH', 100),
];
