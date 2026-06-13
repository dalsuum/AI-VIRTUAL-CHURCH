<?php

namespace App\Jobs;

use App\Models\ServiceAsset;
use App\Models\ServiceSession;
use App\Services\TedimLlmService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Post-generation Tedim localization stage.
 *
 * Dispatched after the English worship service is assembled (e.g. at the end
 * of DispatchServiceJob::handle, or via a WebSocket event listener once all
 * English assets reach 'ready').
 *
 * For each service_assets row that still has no tedim_text:
 *   - scripture  → exact Lai Siangtho corpus lookup (no LLM, instant)
 *   - all prose  → paragraph-by-paragraph Ollama translation (cached 30 days)
 *
 * On the 4-OCPU OCI ARM box this job can take up to an hour for a full
 * service, so timeout is set to 3600 s and it runs on the default queue
 * (not a dedicated Tedim queue) to avoid blocking shorter jobs.
 */
class LocalizeServiceToTedim implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries   = 2;

    public function __construct(public ServiceSession $service) {}

    public function handle(TedimLlmService $llm): void
    {
        // scripture_ref is on ServiceIntake; load it once for the corpus lookup.
        $intake = $this->service->intake;

        $this->service->assets()
            ->whereNull('tedim_text')
            ->orderBy('id')
            ->each(function (ServiceAsset $asset) use ($llm, $intake) {
                if ($asset->segment === 'scripture' && $intake?->scripture_ref) {
                    // Exact verse from the local Tedim corpus; falls back to
                    // LLM translation if the 1932 corpus doesn't cover the ref.
                    $tedim = $llm->verseToTedim($intake->scripture_ref)
                        ?? $llm->translateToTedim($asset->text_payload ?? '');
                } else {
                    $tedim = $llm->translateToTedim($asset->text_payload ?? '');
                }

                $asset->update(['tedim_text' => $tedim]);
            });

        $this->service->update(['tedim_status' => 'ready']);
    }
}
