
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public array $backoff = [10, 30, 60];

    public function __construct(
        public string $url,
        public array $payload
    ) {
    }

    public function handle(): void
    {
        Http::timeout(20)
            ->post($this->url, $this->payload)
            ->throw();
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Webhook job permanently failed.', [
            'url' => $this->url,
            'message' => $exception->getMessage(),
        ]);
    }
}
