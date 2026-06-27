<?php

namespace Tests\Unit\Knowledge;

use App\Services\Knowledge\Data\Chunk;
use App\Services\Knowledge\Data\ChunkMetadata;
use App\Services\Knowledge\Store\InMemoryVectorStore;
use PHPUnit\Framework\TestCase;

/**
 * SECURITY GATE — multi-tenant knowledge isolation.
 *
 * The platform ships with SHARED, read-only corpora (bible, sermon), so there is no tenant
 * boundary to cross today. These tests are the explicit engineering gate for the next step:
 *
 *   >>> Private / tenant-owned knowledge bases MUST NOT be enabled until tenant-scoped vector
 *   >>> filtering is enforced on the retrieval path AND covered by these tests. <<<
 *
 * The first test proves the STORE can isolate when a tenant scope is supplied (the mechanism
 * exists: permission tags like 'church:42' + payload filtering). The second test is the real
 * invariant and is intentionally skipped until the retrieval path makes the tenant filter
 * NON-OPTIONAL — i.e. a caller that forgets to scope the query gets zero rows, not a leak.
 */
class TenantIsolationTest extends TestCase
{
    private function chunk(string $id, string $text, string $tenant): Chunk
    {
        // A tenant-owned chunk is tagged with its owning church; 'public' is reserved for the
        // shared corpora. Embedding is identical so similarity never masks a filtering failure.
        return new Chunk($id, $text, new ChunkMetadata(
            source: 'document',
            permissions: [$tenant],
        ), embedding: [1.0, 0.0, 0.0]);
    }

    public function test_store_isolates_when_tenant_scope_is_supplied(): void
    {
        $store = new InMemoryVectorStore();
        $store->upsert('documents', [
            $this->chunk('a1', 'Tenant A private sermon notes', 'church:A'),
            $this->chunk('b1', 'Tenant B private sermon notes', 'church:B'),
        ]);

        // Tenant B searches, scoped to its own permission tag.
        $hits = $store->search('documents', [1.0, 0.0, 0.0], 10, ['permissions' => ['church:B']]);

        $ids = array_map(static fn ($h) => $h->chunk->id, $hits);

        $this->assertContains('b1', $ids, 'Tenant B must see its own document.');
        $this->assertNotContains('a1', $ids, "Tenant B must NOT see Tenant A's document.");
    }

    /**
     * The shipping invariant: omitting the tenant scope must NOT leak other tenants' chunks.
     * Today an unfiltered search returns everything (correct for shared corpora, unsafe for
     * private ones). Closing this gate means the retrieval path (RetrievalOrchestrator /
     * KnowledgeRetriever) injects the caller's tenant filter for private collections so the
     * scope cannot be forgotten — make this assertion pass, then remove the skip.
     */
    public function test_unscoped_search_must_not_leak_across_tenants(): void
    {
        $this->markTestSkipped(
            'GATE: tenant-scoped vector filtering is not yet enforced. Required before private '
            .'per-church corpora are enabled — make the retrieval path inject the tenant filter '
            .'by default so an unscoped query returns zero cross-tenant rows, then unskip.'
        );

        // @phpstan-ignore-next-line  (executable spec for the closed-gate behaviour)
        $store = new InMemoryVectorStore();
        $store->upsert('documents', [
            $this->chunk('a1', 'Tenant A private', 'church:A'),
            $this->chunk('b1', 'Tenant B private', 'church:B'),
        ]);

        // A retrieval call that FORGOT to scope to the current tenant must still not leak.
        $hits = $store->search('documents', [1.0, 0.0, 0.0], 10);
        $ids = array_map(static fn ($h) => $h->chunk->id, $hits);

        $this->assertSame([], $ids, 'Unscoped retrieval must return nothing for private corpora.');
    }
}
