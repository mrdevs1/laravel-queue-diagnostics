
<?php

use App\Jobs\ProcessWebhook;

ProcessWebhook::dispatch(
    'https://example.com/webhook',
    [
        'event' => 'order.created',
        'order_id' => 1001,
    ]
)->onQueue('webhooks');
