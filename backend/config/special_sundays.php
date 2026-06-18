<?php

/*
|--------------------------------------------------------------------------
| Special Sundays catalog (data-driven, versioned)
|--------------------------------------------------------------------------
|
| Source of truth for observances that bias sermon + worship selection during
| their active window ([Sunday − 2 days .. Sunday end-of-day], i.e. Fri 00:00 →
| Sun 23:59). `php artisan db:seed --class=SpecialSundaySeeder` upserts these
| rows into the `special_sundays` table by `key`; editing this file and
| re-seeding is the supported way to add/adjust observances per region without
| a migration. See README "Adding a special Sunday".
|
| DATES ARE NEVER HARDCODED. Each entry carries a rule that resolves to an
| actual Sunday for any given year (see App\Models\SpecialSunday):
|
|   rule_type = 'nth_weekday'   rule = {month:1-12, weekday:0-6 (0=Sun), nth:±1..5}
|                               nth < 0 counts from the end (last = -1).
|   rule_type = 'easter_offset' rule = {offset:int}  days from Western Easter Sunday
|                               (Palm = -7, Pentecost = +49, Easter = 0).
|   rule_type = 'fixed'         rule = {month, day}  a civil/fixed date.
|
| Any anchor that does not already land on a Sunday is snapped to the NEAREST
| Sunday (within ±3 days) so the church observes it on its worship day.
|
| `priority` breaks ties when two windows overlap (higher wins). `region` is an
| optional scope label (null = global); the resolver currently treats it as
| advisory metadata surfaced to the frontend.
|
| All `my`/`td` title + brief text MUST be authored in Myanmar Unicode (never
| Zawgyi); the seeder runs it through normalization and the API guards output.
*/

return [
    'version' => 1,

    'observances' => [

        // ── Liturgical (Revised Common Lectionary, computed from Western Easter) ──
        [
            'key'         => 'palm_sunday',
            'rule_type'   => 'easter_offset',
            'rule'        => ['offset' => -7],
            'titles'      => [
                'en' => 'Palm Sunday',
                'my' => 'စွန်ပလွံခက်နေ့',
                'td' => 'Tum Ni (Palm Sunday)',
            ],
            'briefs'      => [
                'en' => 'We welcome the King who comes in humility, waving palms as Jesus enters Jerusalem toward the cross.',
                'my' => 'နှိမ့်ချစွာ ကြွလာတော်မူသော ဘုရင်ကို ကြိုဆိုပါ၏။ ယေရှုသည် ယေရုရှလင်မြို့သို့ ဝင်တော်မူ၏။',
                'td' => 'Kiim takna in hong pai Kumpipa i na kipahpih hi; Jesuh Jerusalem khua sungah a luut ni.',
            ],
            'sermon_tags' => ['triumphal entry', 'humility', 'kingship', 'palm sunday', 'jerusalem'],
            'music_moods' => ['worship', 'hopeful', 'reverent'],
            'region'      => null,
            'priority'    => 80,
        ],
        [
            'key'         => 'easter_sunday',
            'rule_type'   => 'easter_offset',
            'rule'        => ['offset' => 0],
            'titles'      => [
                'en' => 'Easter Sunday',
                'my' => 'ထမြောက်ရာနေ့ (အီစတာ)',
                'td' => 'Thawhkikni (Easter)',
            ],
            'briefs'      => [
                'en' => 'Christ is risen! Death is defeated and our living hope is sealed in the empty tomb.',
                'my' => 'ခရစ်တော် ထမြောက်တော်မူပြီ။ သေခြင်းကို အောင်မြင်ပြီး၊ အသက်ရှင်သော မျှော်လင့်ခြင်းကို ရရှိပါ၏။',
                'td' => 'Christ thawhkik hi! Sihna zo a, i nuntakna lametna a hong khinsak hi.',
            ],
            'sermon_tags' => ['resurrection', 'victory', 'empty tomb', 'easter', 'new life'],
            'music_moods' => ['joyful', 'celebrat', 'praise'],
            'region'      => null,
            'priority'    => 100,
        ],
        [
            'key'         => 'pentecost',
            'rule_type'   => 'easter_offset',
            'rule'        => ['offset' => 49],
            'titles'      => [
                'en' => 'Pentecost',
                'my' => 'ပင်တေကုတ္တေနေ့',
                'td' => 'Pentecost Ni',
            ],
            'briefs'      => [
                'en' => 'The Spirit is poured out and the church is born — fire, wind, and bold witness to the nations.',
                'my' => 'သန့်ရှင်းသောဝိညာဉ်တော် သွန်းလောင်းခြင်းခံရပြီး အသင်းတော် ဖွဲ့စည်းတည်ထောင်ခြင်း ဖြစ်ပါ၏။',
                'td' => 'Kha Siangtho hong sung a, pawlpi piang hi; mei, huihpi, leh hangsanna tetti.',
            ],
            'sermon_tags' => ['holy spirit', 'pentecost', 'church', 'power', 'witness'],
            'music_moods' => ['joyful', 'worship', 'praise'],
            'region'      => null,
            'priority'    => 80,
        ],
        [
            'key'         => 'reformation_sunday',
            'rule_type'   => 'nth_weekday',
            'rule'        => ['month' => 10, 'weekday' => 0, 'nth' => -1], // last Sunday of October
            'titles'      => [
                'en' => 'Reformation Sunday',
                'my' => 'ပြုပြင်ပြောင်းလဲရေး တနင်္ဂနွေ',
                'td' => 'Reformation Pathianni',
            ],
            'briefs'      => [
                'en' => 'Saved by grace through faith alone — we remember the recovery of the gospel and the open Word.',
                'my' => 'ယုံကြည်ခြင်းအားဖြင့် ကျေးဇူးတော်ကြောင့်သာ ကယ်တင်ခြင်း — ဧဝံဂေလိတရား ပြန်လည်ထွန်းကားလာခြင်းကို အောက်မေ့ပါ၏။',
                'td' => 'Upna tungtawn hehpihna in hotkhiatna — lungdamna thu kiphuankikna i phawk hi.',
            ],
            'sermon_tags' => ['grace', 'faith', 'gospel', 'scripture', 'reformation'],
            'music_moods' => ['worship', 'reverent', 'hopeful'],
            'region'      => null,
            'priority'    => 60,
        ],
        [
            'key'         => 'advent_first',
            'rule_type'   => 'fixed',
            'rule'        => ['month' => 11, 'day' => 30], // St Andrew's Day anchor; snaps to the nearest Sunday = Advent 1
            'titles'      => [
                'en' => 'First Sunday of Advent',
                'my' => 'အဒ်ဗင့် ပထမတနင်္ဂနွေ',
                'td' => 'Advent Pathianni Masapen',
            ],
            'briefs'      => [
                'en' => 'We begin the season of waiting — hope kindled as we prepare our hearts for the coming of Christ.',
                'my' => 'စောင့်မျှော်ခြင်းကာလ စတင်ပါ၏။ ခရစ်တော် ကြွလာတော်မူခြင်းအတွက် နှလုံးသားကို ပြင်ဆင်ရင်း မျှော်လင့်ခြင်း ထွန်းလင်းပါ၏။',
                'td' => 'Ngakna hun i kipan; Christ hongpaina ading i lungtang kiginkholhna lametna hong tang hi.',
            ],
            'sermon_tags' => ['advent', 'hope', 'waiting', 'preparation', 'incarnation'],
            'music_moods' => ['hopeful', 'reverent', 'peace'],
            'region'      => null,
            'priority'    => 60,
        ],

        // ── Civil observances (editable per region; snap to the worship Sunday) ──
        [
            'key'         => 'mothers_day',
            'rule_type'   => 'nth_weekday',
            'rule'        => ['month' => 5, 'weekday' => 0, 'nth' => 2], // 2nd Sunday of May
            'titles'      => [
                'en' => "Mother's Day",
                'my' => 'အမေများနေ့',
                'td' => 'Nu Ni (Mother\'s Day)',
            ],
            'briefs'      => [
                'en' => 'We give thanks for mothers and the love that nurtures us, honoring them before the Lord today.',
                'my' => 'အမေများနှင့် ကျွန်ုပ်တို့ကို ပြုစုပျိုးထောင်သော မေတ္တာအတွက် ကျေးဇူးတင်ပါ၏။',
                'td' => 'Nu te leh amau itna ading lungdam i ko a, tu ni in Topa maiah i zahtak hi.',
            ],
            'sermon_tags' => ['motherhood', 'family', 'honor your parents', 'love', 'gratitude'],
            'music_moods' => ['grateful', 'love', 'peace'],
            'region'      => null,
            'priority'    => 50,
        ],
        [
            'key'         => 'fathers_day',
            'rule_type'   => 'nth_weekday',
            'rule'        => ['month' => 6, 'weekday' => 0, 'nth' => 3], // 3rd Sunday of June
            'titles'      => [
                'en' => "Father's Day",
                'my' => 'အဖေများနေ့',
                'td' => 'Pa Ni (Father\'s Day)',
            ],
            'briefs'      => [
                'en' => 'We honor fathers and the steady, sacrificial love that points us to our Father in heaven.',
                'my' => 'အဖေများနှင့် ကောင်းကင်ဘုံရှိ အဖခမည်းတော်ဆီသို့ ညွှန်ပြသော ခိုင်မြဲသည့် မေတ္တာအတွက် ဂုဏ်ပြုပါ၏။',
                'td' => 'Pa te leh vana i Pa lam hong lak a kician itna i zahtak hi.',
            ],
            'sermon_tags' => ['fatherhood', 'family', 'honor your parents', 'godly leadership', 'love'],
            'music_moods' => ['grateful', 'strength', 'peace'],
            'region'      => null,
            'priority'    => 50,
        ],
        [
            'key'         => 'childrens_day',
            'rule_type'   => 'fixed',
            'rule'        => ['month' => 6, 'day' => 1], // International Children's Day; snaps to nearest Sunday
            'titles'      => [
                'en' => "Children's Day",
                'my' => 'ကလေးများနေ့',
                'td' => 'Naupang Ni (Children\'s Day)',
            ],
            'briefs'      => [
                'en' => 'Let the little children come — we celebrate and bless the youngest among us as a gift from God.',
                'my' => 'ကလေးသူငယ်တို့ ငါ့ထံသို့ လာကြစေ — ဘုရားသခင်၏ ဆုကျေးဇူးအဖြစ် ကလေးများကို ဂုဏ်ပြုကောင်းချီးပေးပါ၏။',
                'td' => 'Naupang te ka kiangah hong pai uhheh — Pasian letsong bangin naupang te i thupha hi.',
            ],
            'sermon_tags' => ['children', 'faith like a child', 'blessing', 'family', 'discipleship'],
            'music_moods' => ['joyful', 'hopeful', 'praise'],
            'region'      => null,
            'priority'    => 45,
        ],
        [
            'key'         => 'youth_day',
            'rule_type'   => 'nth_weekday',
            'rule'        => ['month' => 8, 'weekday' => 0, 'nth' => 2], // 2nd Sunday of August (region-editable)
            'titles'      => [
                'en' => 'Youth Day',
                'my' => 'လူငယ်များနေ့',
                'td' => 'Tangval Ni (Youth Day)',
            ],
            'briefs'      => [
                'en' => 'We commission the next generation — bold, faithful, and called to let no one despise their youth.',
                'my' => 'နောက်လူမျိုးဆက်ကို တာဝန်ပေးအပ်ပါ၏ — ရဲရင့်၍ သစ္စာရှိစွာ ဘုရားသခင်အတွက် အသက်ရှင်ကြစေ။',
                'td' => 'Khangthak suankhat te i sawl hi — hangsan, muanhuai, kuamah in tangvalna honmusit khол louhna.',
            ],
            'sermon_tags' => ['youth', 'calling', 'courage', 'discipleship', 'faithfulness'],
            'music_moods' => ['hopeful', 'joyful', 'strength'],
            'region'      => null,
            'priority'    => 45,
        ],
        [
            'key'         => 'thanksgiving_sunday',
            'rule_type'   => 'nth_weekday',
            'rule'        => ['month' => 11, 'weekday' => 4, 'nth' => 4], // US Thanksgiving (4th Thu Nov); snaps to nearest Sunday
            'titles'      => [
                'en' => 'Thanksgiving Sunday',
                'my' => 'ကျေးဇူးတော်ချီးမွမ်းရာ တနင်္ဂနွေ',
                'td' => 'Lungdam Kohna Pathianni',
            ],
            'briefs'      => [
                'en' => 'We count our blessings and give thanks in all things to the God from whom every good gift flows.',
                'my' => 'ကောင်းချီးများကို ရေတွက်၍ အရာရာ၌ ကျေးဇူးတော်ကို ချီးမွမ်းပါ၏။',
                'td' => 'Thupha te i sim a, na tengtengah letsong hoih a hong piakpa Pasian tungah lungdam i ko hi.',
            ],
            'sermon_tags' => ['thanksgiving', 'gratitude', 'provision', 'contentment', 'praise'],
            'music_moods' => ['grateful', 'thankful', 'praise'],
            'region'      => null,
            'priority'    => 55,
        ],
    ],
];
