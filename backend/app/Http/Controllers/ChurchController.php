<?php

namespace App\Http\Controllers;

use App\Domains\Bible\Models\ReadingSession;
use App\Domains\Church\Models\Church;
use App\Domains\Groups\Models\Group;
use App\Domains\Groups\Models\GroupMembership;
use App\Enums\GroupType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Church surface: the churches I belong to, a church's roster and public profile
 * (members), and profile administration (elders+ via ChurchPolicy::manage). The
 * profile lives under settings['profile'] — the existing JSON column, no schema
 * growth; logo/banner are public-disk files whose paths live there too.
 */
class ChurchController extends Controller
{
    /** Platforms a church may link; anything else is rejected at validation. */
    private const SOCIAL_PLATFORMS = ['facebook', 'instagram', 'youtube', 'x', 'telegram', 'whatsapp'];

    /** Churches the authenticated user is an active member of, with their role. */
    public function index(Request $request)
    {
        $churches = $request->user()->churchMemberships()
            ->where('status', \App\Domains\Church\Models\ChurchMembership::STATUS_ACTIVE)
            ->with('church')
            ->get()
            ->map(fn ($m) => [
                'id'   => $m->church_id,
                'name' => $m->church?->name,
                'role' => $m->role->value,
            ]);

        return response()->json(['churches' => $churches]);
    }

    /** Member directory — members and above (ChurchPolicy::viewDirectory; guests
     *  don't enumerate the roster). Carries each member's group names so the
     *  directory needs no follow-up calls; search/filtering happens client-side. */
    public function members(Request $request, Church $church)
    {
        $this->authorize('viewDirectory', $church);

        $memberships  = $church->memberships()->with('user')->get();
        $groupsByUser = GroupMembership::query()
            ->whereIn('user_id', $memberships->pluck('user_id'))
            ->where('status', GroupMembership::STATUS_ACTIVE)
            ->whereHas('group', fn ($q) => $q->where('church_id', $church->id))
            ->with('group')->get()->groupBy('user_id');

        $members = $memberships->map(fn ($m) => [
            'id'        => $m->user_id,
            'name'      => $m->user?->name,
            'role'      => $m->role->value,
            'status'    => $m->status,
            'joined_at' => optional($m->joined_at)->toIso8601String(),
            'groups'    => ($groupsByUser[$m->user_id] ?? collect())
                ->map(fn ($gm) => $gm->group?->name)->filter()->values(),
        ]);

        return response()->json(['members' => $members]);
    }

    /**
     * Church-wide activity feed (v1.3 Phase F) — CURATED, not complete: joins,
     * new groups, sessions going live, recent reading completions. Link mints and
     * request lifecycle are deliberately omitted (manager-only context, noise at
     * church scope). Like the group feed, this is a projection over existing rows;
     * a persisted feed on the frozen events can replace it behind the same contract.
     */
    public function activity(Request $request, Church $church)
    {
        $this->authorize('viewDirectory', $church);   // feed names members; guests don't see it

        $groupIds = $church->groups()->pluck('id');

        $churchJoins = \App\Domains\Church\Models\ChurchMembership::query()
            ->where('church_id', $church->id)->where('status', 'active')
            ->whereNotNull('joined_at')->with('user')
            ->latest('joined_at')->limit(15)->get()
            ->map(fn ($m) => ['type' => 'member_joined_church', 'at' => $m->joined_at, 'actor' => $m->user?->name, 'subject' => null]);

        $groupJoins = GroupMembership::query()
            ->whereIn('group_id', $groupIds)->where('status', GroupMembership::STATUS_ACTIVE)
            ->whereNotNull('joined_at')->with(['user', 'group'])
            ->latest('joined_at')->limit(15)->get()
            ->map(fn ($m) => ['type' => 'member_joined_group', 'at' => $m->joined_at, 'actor' => $m->user?->name, 'subject' => $m->group?->name]);

        $newGroups = Group::query()->where('church_id', $church->id)
            ->latest()->limit(10)->get()
            ->map(fn ($g) => ['type' => 'group_created', 'at' => $g->created_at, 'actor' => null, 'subject' => $g->name]);

        $sessions = ReadingSession::query()->whereIn('group_id', $groupIds)
            ->whereNotNull('started_at')->with(['plan', 'group'])
            ->latest('started_at')->limit(10)->get()
            ->map(fn ($s) => ['type' => 'session_started', 'at' => $s->started_at, 'actor' => $s->group?->name, 'subject' => $s->plan?->title]);

        $completions = \App\Domains\Bible\Models\ReadingParticipant::query()
            ->whereHas('session', fn ($q) => $q->whereIn('group_id', $groupIds))
            ->whereHas('enrollment', fn ($q) => $q->where('last_read_on', '>=', now()->subDays(2)->toDateString()))
            ->with(['user', 'enrollment'])->limit(15)->get()
            ->map(fn ($p) => [
                'type'    => 'reading_completed',
                'at'      => \Illuminate\Support\Carbon::parse($p->enrollment->last_read_on),
                'actor'   => $p->user?->name,
                'subject' => null,
            ]);

        $items = $churchJoins->concat($groupJoins)->concat($newGroups)
            ->concat($sessions)->concat($completions)
            ->filter(fn ($e) => $e['at'] !== null)
            ->sortByDesc('at')->take(20)->values()
            ->map(fn ($e) => [...$e, 'at' => $e['at']->toIso8601String()]);

        return response()->json(['activity' => $items]);
    }

    /** A church's ministry groups with the viewer's own context (v1.3 Phase F —
     *  first consumer of the Groups domain over HTTP). Visible to any member. */
    public function groups(Request $request, Church $church)
    {
        $this->authorize('view', $church);

        $groups = $church->groups()
            ->withCount(['memberships as member_count' => fn ($q) => $q->where('status', GroupMembership::STATUS_ACTIVE)])
            ->with(['readingSessions' => fn ($q) => $q->whereNotIn('status', ReadingSession::TERMINAL)->with('plan')])
            ->orderBy('name')->get()
            ->map(fn (Group $g) => [
                'id'           => $g->id,
                'name'         => $g->name,
                'type'         => $g->type->value,
                'description'  => $g->description,
                'member_count' => $g->member_count,
                'my_role'      => $request->user()->groupRole($g->id)?->value,
                'open_session' => ($s = $g->readingSessions->first()) ? [
                    'id' => $s->id, 'status' => $s->status, 'plan_title' => $s->plan?->title,
                ] : null,
            ]);

        return response()->json(['groups' => $groups]);
    }

    /** Create a group (GroupPolicy::create — church leaders and above). */
    public function storeGroup(Request $request, Church $church)
    {
        $this->authorize('create', [Group::class, $church]);

        $data = $request->validate([
            'name'        => ['required', 'string', 'min:2', 'max:120',
                Rule::unique('groups', 'name')->where('church_id', $church->id)],
            'type'        => ['required', new Enum(GroupType::class)],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $group = $church->groups()->create($data);

        return response()->json([
            'id'           => $group->id,
            'name'         => $group->name,
            'type'         => $group->type->value,
            'description'  => $group->description,
            'member_count' => 0,
            'my_role'      => null,
            'open_session' => null,
        ], 201);
    }

    /** The church profile (v1.3 Phase E) — visible to any member. */
    public function show(Request $request, Church $church)
    {
        $this->authorize('view', $church);

        return response()->json($this->presentProfile($church));
    }

    /** Update profile fields (elders+). Merge semantics: only provided keys change;
     *  everything else in settings — profile or operational — is preserved. */
    public function updateProfile(Request $request, Church $church)
    {
        $this->authorize('manage', $church);

        $rules = [
            'name'          => ['sometimes', 'string', 'min:2', 'max:120'],
            'timezone'      => ['sometimes', 'nullable', 'timezone'],
            'description'   => ['sometimes', 'nullable', 'string', 'max:1000'],
            'address'       => ['sometimes', 'nullable', 'string', 'max:300'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:120'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'website'       => ['sometimes', 'nullable', 'url', 'max:200'],
            'socials'       => ['sometimes', 'array:'.implode(',', self::SOCIAL_PLATFORMS)],
            'languages'     => ['sometimes', 'array', 'max:20'],
            'languages.*'   => ['string', Rule::in(array_keys((array) config('languages.list')))],
        ];
        foreach (self::SOCIAL_PLATFORMS as $platform) {
            $rules["socials.{$platform}"] = ['sometimes', 'nullable', 'url', 'max:200'];
        }
        $data = $request->validate($rules);

        if (array_key_exists('name', $data)) {
            $church->name = $data['name'];   // slug stays — it is a stable identifier
        }
        if (array_key_exists('timezone', $data)) {
            $church->timezone = $data['timezone'];
        }

        $settings = $church->settings ?? [];
        $profile  = (array) ($settings['profile'] ?? []);
        foreach (['description', 'address', 'contact_email', 'contact_phone', 'website', 'socials', 'languages'] as $key) {
            if (array_key_exists($key, $data)) {
                $profile[$key] = $data[$key];
            }
        }
        $settings['profile'] = $profile;
        $church->settings = $settings;
        $church->save();

        return response()->json($this->presentProfile($church));
    }

    public function uploadLogo(Request $request, Church $church)
    {
        return $this->storeImage($request, $church, 'logo', maxKb: 2048);
    }

    public function uploadBanner(Request $request, Church $church)
    {
        return $this->storeImage($request, $church, 'banner', maxKb: 4096);
    }

    /** Shared image handling (elders+): validate, replace the old file, persist the
     *  path in the profile. Follows the AdController public-disk pattern. */
    private function storeImage(Request $request, Church $church, string $kind, int $maxKb)
    {
        $this->authorize('manage', $church);

        $request->validate([
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.$maxKb],
        ]);

        $settings = $church->settings ?? [];
        $profile  = (array) ($settings['profile'] ?? []);

        if (! empty($profile[$kind.'_path'])) {
            Storage::disk('public')->delete($profile[$kind.'_path']);
        }

        $path = $request->file('image')->store("churches/{$church->id}", 'public');
        $profile[$kind.'_path'] = $path;
        $settings['profile'] = $profile;
        $church->forceFill(['settings' => $settings])->save();

        return response()->json([$kind.'_url' => Storage::disk('public')->url($path)]);
    }

    /** Stable API projection of a church profile (paths become public URLs). */
    private function presentProfile(Church $church): array
    {
        $p = (array) (($church->settings ?? [])['profile'] ?? []);

        return [
            'id'            => $church->id,
            'name'          => $church->name,
            'slug'          => $church->slug,
            'timezone'      => $church->timezone,
            'description'   => $p['description'] ?? null,
            'address'       => $p['address'] ?? null,
            'contact_email' => $p['contact_email'] ?? null,
            'contact_phone' => $p['contact_phone'] ?? null,
            'website'       => $p['website'] ?? null,
            'socials'       => $p['socials'] ?? [],
            'languages'     => $p['languages'] ?? [],
            'logo_url'      => ! empty($p['logo_path']) ? Storage::disk('public')->url($p['logo_path']) : null,
            'banner_url'    => ! empty($p['banner_path']) ? Storage::disk('public')->url($p['banner_path']) : null,
        ];
    }
}
