<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeIngestJob extends Model
{
    protected $fillable = [
        'created_by',
        'collection',
        'original_filename',
        'file_hash',
        'idempotency_key',
        'file_size',
        'status',
        'document_count',
        'chunk_count',
        'duration_ms',
        'error_message',
        'metadata',
        'storage_path',
    ];

    protected $casts = [
        'metadata'       => 'array',
        'file_size'      => 'integer',
        'document_count' => 'integer',
        'chunk_count'    => 'integer',
        'duration_ms'    => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
