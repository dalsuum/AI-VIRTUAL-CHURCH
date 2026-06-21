<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Salted-hash record of an anonymous visitor's consumed services. Managed by
 * App\Services\GuestUsageService — see the create_guest_tracking_table migration.
 */
class GuestTracking extends Model
{
    protected $table = 'guest_tracking';

    protected $fillable = ['ip_hash', 'fingerprint_hash', 'services_used'];

    protected $casts = [
        'services_used' => 'array',
    ];
}
