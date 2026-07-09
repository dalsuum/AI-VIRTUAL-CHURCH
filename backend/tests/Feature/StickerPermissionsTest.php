<?php

namespace Tests\Feature;

use App\Http\Controllers\StickerController;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression: the render worker runs as a DIFFERENT OS user in the www-data
 * group and reaches the job files via group access. Laravel's local-disk root
 * (storage/app/private) is created 0700 (owner-only), which blocks the worker
 * from even traversing into the stickers tree — leaving renders stuck at
 * "Queued". ensureBasePerms() must add group-traverse (g+x) to that root while
 * keeping it otherwise closed (no group read/write, no "others" access).
 */
class StickerPermissionsTest extends TestCase
{
    public function test_ensure_base_perms_grants_group_traverse_on_private_root(): void
    {
        Storage::fake();

        // The disk root as the controller derives it: parent of the stickers dir.
        $root = dirname(Storage::path('stickers'));
        chmod($root, 0700);                     // reproduce the owner-only default
        $this->assertSame(0700, fileperms($root) & 07777);

        $m = new \ReflectionMethod(StickerController::class, 'ensureBasePerms');
        $m->setAccessible(true);
        $m->invoke(new StickerController());

        $mode = fileperms($root) & 07777;
        $this->assertSame(0010, $mode & 0010, 'group-execute (traverse) must be set');
        $this->assertSame(0700, $mode & 0700, 'owner bits must be preserved');
        $this->assertSame(0000, $mode & 0006, 'others must stay closed (no read/write)');
        $this->assertSame(0000, $mode & 0060, 'group read/write must stay closed (least privilege)');
    }
}
