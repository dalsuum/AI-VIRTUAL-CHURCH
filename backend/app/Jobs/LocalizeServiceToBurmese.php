<?php

namespace App\Jobs;

use App\Models\ServiceAsset;
use App\Models\ServiceSession;
use App\Services\BurmeseLlmService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Post-generation Burmese localization stage.
 *
 * Dispatched from WebhookController after all four core text segments reach
 * 'ready' for a Myanmar-language service. Fills burmese_text on every asset
 * that doesn't have it yet using the local Ollama burmese-myanmar model:
 *
 *   - scripture  → exact Judson 1835 corpus lookup (no LLM, instant)
 *   - all prose  → paragraph-by-paragraph Ollama translation (cached 30 days)
 *
 * On the 4-OCPU OCI ARM box this job can take up to an hour for a full
 * service, so timeout is set to 3600 s. The local model output is Myanmar
 * Unicode — never Zawgyi — matching what edge-tts my-MM voices expect.
 */
class LocalizeServiceToBurmese implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries   = 2;

    public function __construct(public ServiceSession $service) {}

    public function handle(BurmeseLlmService $llm): void
    {
        $intake = $this->service->intake;

        $this->service->assets()
            ->whereNull('burmese_text')
            ->orderBy('id')
            ->each(function (ServiceAsset $asset) use ($llm, $intake) {
                if ($asset->segment === 'scripture' && $intake?->scripture_ref) {
                    // Exact verse from the local Myanmar corpus; falls back to
                    // LLM translation if the Judson 1835 corpus doesn't cover the ref.
                    $burmese = $llm->verseToBurmese($intake->scripture_ref)
                        ?? $llm->translateToBurmese($asset->text_payload ?? '');
                } else {
                    $burmese = $llm->translateToBurmese($asset->text_payload ?? '');
                }

                $asset->update(['burmese_text' => $burmese]);
            });

        $this->service->update(['burmese_status' => 'ready']);
    }
}
