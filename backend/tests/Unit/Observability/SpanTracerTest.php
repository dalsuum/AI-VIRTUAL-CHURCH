<?php

namespace Tests\Unit\Observability;

use App\Services\Observability\SpanTracer;
use App\Services\Observability\Store\ArrayTraceStore;
use PHPUnit\Framework\TestCase;

/**
 * Pure tracer tests: nesting from call order, attribute annotation, persistence on root end, and
 * the zero-overhead "no active trace" path. No infra.
 */
class SpanTracerTest extends TestCase
{
    public function test_builds_nested_tree_and_persists_on_root_end(): void
    {
        $store = new ArrayTraceStore();
        $tracer = new SpanTracer($store);

        $result = $tracer->trace('cid-1', 'chat.request', function () use ($tracer) {
            $tracer->span('guardrails.pre', function () use ($tracer) {
                $tracer->annotate(['verdict' => 'pass']);
            });
            $tracer->span('inference.llm', fn () => 'ok', ['model' => 'sonnet']);

            return 'done';
        }, ['route' => 'bible_study']);

        $this->assertSame('done', $result, 'trace returns the callback result');

        $trace = $store->get('cid-1');
        $this->assertNotNull($trace);
        $this->assertSame('chat.request', $trace['name']);
        $this->assertSame('cid-1', $trace['correlation_id']);
        $this->assertSame('bible_study', $trace['attributes']['route']);

        $children = $trace['children'];
        $this->assertCount(2, $children);
        $this->assertSame('guardrails.pre', $children[0]['name']);
        $this->assertSame('pass', $children[0]['attributes']['verdict']);
        $this->assertSame('inference.llm', $children[1]['name']);
        $this->assertSame('sonnet', $children[1]['attributes']['model']);
        $this->assertIsInt($trace['duration_ms']);
    }

    public function test_span_without_active_trace_records_nothing(): void
    {
        $store = new ArrayTraceStore();
        $tracer = new SpanTracer($store);

        $value = $tracer->span('orphan', fn () => 42);

        $this->assertSame(42, $value, 'work still runs');
        $this->assertNull($store->get('any'), 'no orphan trace persisted');
    }
}
