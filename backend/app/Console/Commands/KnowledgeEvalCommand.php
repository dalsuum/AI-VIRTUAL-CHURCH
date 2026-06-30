<?php

namespace App\Console\Commands;

use App\Services\Knowledge\Retrieval\RetrievalOrchestrator;
use Illuminate\Console\Command;

/**
 * RAG acceptance / regression eval — the "unit tests for retrieval" the platform needs so a
 * deploy can verify retrieval ACCURACY, CITATION correctness and LANGUAGE correctness, not just
 * that code compiles. Runs real queries through the live RetrievalOrchestrator (worker embeddings
 * + Qdrant) and asserts the right Scripture comes back, cited correctly, in the right language.
 *
 * Two case kinds:
 *   • English SEMANTIC — natural near-verbatim questions → expected book+chapter in top-k.
 *   • Per-language ANCHOR roundtrip — uses the verse's OWN text (read from the exported
 *     storage/app/knowledge/bible_<lang>.json, so nothing is hand-transcribed) as the query and
 *     asserts that exact reference returns AND every bible chunk is in the requested language.
 *
 * Exits non-zero if any case fails (skips don't fail), so CI/deploy can gate on it.
 *
 *   php artisan knowledge:eval                 # all conversation languages with data present
 *   php artisan knowledge:eval --lang=my       # one language
 *   php artisan knowledge:eval --k=10
 */
final class KnowledgeEvalCommand extends Command
{
    protected $signature = 'knowledge:eval {--lang= : restrict to one language code} {--k=8 : top-k to inspect}';

    protected $description = 'Acceptance/regression eval for Bible + sermon RAG retrieval (accuracy, citation, language)';

    /** Verses present across the full canon — used for the per-language roundtrip. */
    private const ANCHORS = ['John 3:16', 'Genesis 1:1', 'Psalm 23:1'];

    /** Near-verbatim English questions → the reference whose book+chapter must surface. */
    private const ENGLISH_SEMANTIC = [
        ['q' => 'For God so loved the world that he gave his only Son', 'ref' => 'John 3:16'],
        ['q' => 'The Lord is my shepherd, I shall not want', 'ref' => 'Psalms 23:1'],
        ['q' => 'In the beginning God created the heavens and the earth', 'ref' => 'Genesis 1:1'],
        ['q' => 'Now faith is the assurance of what we hope for', 'ref' => 'Hebrews 11:1'],
        ['q' => 'The fruit of the Spirit is love, joy, and peace', 'ref' => 'Galatians 5:22'],
        ['q' => 'I can do all things through him who gives me strength', 'ref' => 'Philippians 4:13'],
        ['q' => 'Be still and know that I am God', 'ref' => 'Psalms 46:10'],
    ];

    public function handle(RetrievalOrchestrator $orchestrator): int
    {
        if (! config('knowledge.enabled')) {
            $this->error('knowledge.enabled is false — RAG is not active, nothing to evaluate.');

            return self::FAILURE;
        }

        $k = max(1, (int) $this->option('k'));
        $only = $this->option('lang');
        $langs = $only ? [$only] : array_keys((array) config('languages.list', ['en' => []]));

        $pass = 0;
        $fail = 0;
        $skip = 0;
        $latencies = [];

        foreach ($langs as $lang) {
            $this->line("\n<info>=== {$lang} ===</info>");

            // English also exercises the semantic (non-verbatim) path.
            if ($lang === 'en') {
                foreach (self::ENGLISH_SEMANTIC as $case) {
                    [$ok, $detail, $ms] = $this->runCase($orchestrator, 'en', $case['q'], $case['ref'], $k, exact: false);
                    $latencies[] = $ms;
                    $ok ? $pass++ : $fail++;
                    $this->line(($ok ? '  <fg=green>PASS</>' : '  <fg=red>FAIL</>') . " semantic «{$case['ref']}»  {$detail}");
                }
            }

            $verses = $this->anchorTexts($lang);
            if ($verses === null) {
                $this->line("  <comment>SKIP</comment> no exported bible_{$lang}.json on disk (not ingested?)");
                $skip++;
                continue;
            }

            foreach (self::ANCHORS as $ref) {
                if (! isset($verses[$ref])) {
                    continue; // e.g. NT anchor under the Hebrew Tanakh
                }
                [$ok, $detail, $ms] = $this->runCase($orchestrator, $lang, $verses[$ref], $ref, $k, exact: true);
                $latencies[] = $ms;
                $ok ? $pass++ : $fail++;
                $this->line(($ok ? '  <fg=green>PASS</>' : '  <fg=red>FAIL</>') . " anchor «{$ref}»  {$detail}");
            }
        }

        $avg = $latencies ? array_sum($latencies) / count($latencies) : 0;
        $this->line(sprintf("\n<info>%d passed, %d failed, %d skipped</info> — avg retrieval latency %dms", $pass, $fail, $skip, (int) round($avg)));

        return $fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{0:bool,1:string,2:int} [passed, detail, latencyMs]
     */
    private function runCase(RetrievalOrchestrator $orchestrator, string $lang, string $query, string $expectRef, int $k, bool $exact): array
    {
        $start = microtime(true);
        $outcome = $orchestrator->retrieve($query, ['language' => $lang]);
        $ms = (int) round((microtime(true) - $start) * 1000);

        $bible = array_values(array_filter(
            array_slice($outcome->chunks, 0, $k),
            static fn ($c) => $c->corpus === 'bible',
        ));

        $refs = array_map(static fn ($c) => (string) $c->chunk->metadata->reference, $bible);
        $wrongLang = array_values(array_filter($bible, static fn ($c) => $c->chunk->metadata->language !== $lang));

        // Match exactly ("John 3:16") for anchors, or by book+chapter ("John 3:") for semantic.
        $needle = $exact ? $expectRef : substr($expectRef, 0, strrpos($expectRef, ':') + 1);
        $hit = false;
        foreach ($refs as $r) {
            if ($exact ? $r === $needle : str_starts_with($r, $needle)) {
                $hit = true;
                break;
            }
        }

        $langOk = $wrongLang === [];
        $passed = $hit && $langOk && $bible !== [];

        $detail = sprintf('[%dms] bible_hits=%d', $ms, count($bible));
        if (! $hit) {
            $detail .= ' MISS(top: ' . implode(', ', array_slice($refs, 0, 3)) . ')';
        }
        if (! $langOk) {
            $detail .= ' LANG-LEAK(' . implode(',', array_map(static fn ($c) => $c->chunk->metadata->language, $wrongLang)) . ')';
        }

        return [$passed, $detail, $ms];
    }

    /**
     * Map of "Book C:V" => verse text for the anchor verses, read from the exported corpus so
     * the query text is the project's own data, never transcribed. Null if the file is absent.
     *
     * @return array<string,string>|null
     */
    private function anchorTexts(string $lang): ?array
    {
        $path = storage_path("app/knowledge/bible_{$lang}.json");
        if (! is_file($path)) {
            return null;
        }

        $wanted = array_flip(self::ANCHORS);
        $found = [];
        $docs = json_decode((string) file_get_contents($path), true);
        foreach (is_array($docs) ? $docs : [] as $doc) {
            $ref = (string) ($doc['metadata']['reference'] ?? '');
            if (isset($wanted[$ref]) && ($doc['text'] ?? '') !== '') {
                $found[$ref] = (string) $doc['text'];
            }
        }

        return $found;
    }
}
