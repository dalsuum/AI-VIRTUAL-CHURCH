<?php

namespace App\Console\Commands;

use App\Models\BibleSessionMeta;
use App\Models\ChatSession;
use App\Models\StudySession;
use App\Models\StudySummary;
use Illuminate\Console\Command;

/**
 * One-shot backfill: create a unified chat_sessions row (+ bible_sessions bridge)
 * for every pre-existing Bible Study so historical studies show up in the sidebar.
 * Idempotent — studies already bridged are skipped.
 *
 *   php artisan history:backfill-study
 */
class BackfillStudyHistory extends Command
{
    protected $signature = 'history:backfill-study {--chunk=200 : Rows processed per batch}';

    protected $description = 'Bridge existing Bible Study sessions into the unified history sidebar';

    public function handle(): int
    {
        $linked = BibleSessionMeta::whereNotNull('study_session_id')->pluck('study_session_id')->all();
        $linked = array_flip($linked);
        $created = 0;

        StudySession::whereNotNull('user_id')
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($studies) use (&$created, $linked) {
                foreach ($studies as $study) {
                    if (isset($linked[$study->id])) {
                        continue;
                    }

                    $summaryRow = StudySummary::where('session_id', $study->id)->first();
                    $summary = $summaryRow
                        ? mb_substr((string) (is_array($summaryRow->lessons) ? implode(' ', $summaryRow->lessons) : $summaryRow->lessons), 0, 1000)
                        : null;

                    $chat = ChatSession::create([
                        'user_id'          => $study->user_id,
                        'session_type'     => 'bible_study',
                        'title'            => $study->topic ?: 'Bible Study',
                        'language'         => $study->language,
                        'mood'             => $study->mood,
                        'summary'          => $summary,
                        'status'           => in_array($study->state, ['summarized', 'closed'], true) ? 'completed' : 'active',
                        'started_at'       => $study->created_at,
                        'last_activity_at' => $study->last_activity_at ?: $study->created_at,
                        'ended_at'         => in_array($study->state, ['summarized', 'closed'], true) ? $study->updated_at : null,
                    ]);

                    BibleSessionMeta::create([
                        'chat_session_id'    => $chat->id,
                        'study_session_id'   => $study->id,
                        'translation'        => $study->translation,
                        'discussion_summary' => $summary,
                    ]);

                    $created++;
                }
            });

        $this->info("Backfilled {$created} Bible Study session(s) into unified history.");

        return self::SUCCESS;
    }
}
