<?php

namespace App\Services\Observability;

/**
 * One node in a trace tree. OpenTelemetry-shaped (name + attributes + timing + children) but
 * dependency-free, so the platform can emit verifiable execution traces without pulling in the
 * full OTel SDK before it is warranted. Attributes are OBSERVATIONAL only — counts, latencies,
 * classifications, references+scores — never raw chunk text or secrets.
 */
final class Span
{
    /** @var list<Span> */
    private array $children = [];
    /** @var array<string,mixed> */
    private array $attributes;
    private readonly float $startMs;
    private ?float $endMs = null;

    /** @param array<string,mixed> $attributes */
    public function __construct(public readonly string $name, array $attributes = [])
    {
        $this->attributes = $attributes;
        $this->startMs = microtime(true) * 1000;
    }

    public function child(Span $span): void
    {
        $this->children[] = $span;
    }

    /** @param array<string,mixed> $attributes */
    public function annotate(array $attributes): void
    {
        $this->attributes = array_merge($this->attributes, $attributes);
    }

    public function end(): void
    {
        $this->endMs ??= microtime(true) * 1000;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'duration_ms' => $this->endMs === null ? null : (int) round($this->endMs - $this->startMs),
            'attributes'  => $this->attributes,
            'children'    => array_map(static fn (Span $c) => $c->toArray(), $this->children),
        ];
    }
}
