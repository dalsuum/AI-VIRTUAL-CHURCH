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
                'fr' => 'Dimanche des Rameaux',
                'de' => 'Palmsonntag',
                'es' => 'Domingo de Ramos',
            ],
            'briefs'      => [
                'en' => 'We welcome the King who comes in humility, waving palms as Jesus enters Jerusalem toward the cross.',
                'my' => 'နှိမ့်ချစွာ ကြွလာတော်မူသော ဘုရင်ကို ကြိုဆိုပါ၏။ ယေရှုသည် ယေရုရှလင်မြို့သို့ ဝင်တော်မူ၏။',
                'td' => 'Kiim takna in hong pai Kumpipa i na kipahpih hi; Jesuh Jerusalem khua sungah a luut ni.',
                'fr' => "Nous accueillons le Roi qui vient dans l'humilité, tandis que Jésus entre à Jérusalem vers la croix.",
                'de' => 'Wir begrüßen den König, der in Demut kommt, während Jesus nach Jerusalem auf das Kreuz zugeht.',
                'es' => 'Recibimos al Rey que viene con humildad, mientras Jesús entra en Jerusalén camino a la cruz.',
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
                'fr' => 'Dimanche de Pâques',
                'de' => 'Ostersonntag',
                'es' => 'Domingo de Pascua',
            ],
            'briefs'      => [
                'en' => 'Christ is risen! Death is defeated and our living hope is sealed in the empty tomb.',
                'my' => 'ခရစ်တော် ထမြောက်တော်မူပြီ။ သေခြင်းကို အောင်မြင်ပြီး၊ အသက်ရှင်သော မျှော်လင့်ခြင်းကို ရရှိပါ၏။',
                'td' => 'Christ thawhkik hi! Sihna zo a, i nuntakna lametna a hong khinsak hi.',
                'fr' => 'Le Christ est ressuscité ! La mort est vaincue et notre espérance vivante est scellée dans le tombeau vide.',
                'de' => 'Christus ist auferstanden! Der Tod ist besiegt, und unsere lebendige Hoffnung ist im leeren Grab besiegelt.',
                'es' => '¡Cristo ha resucitado! La muerte ha sido vencida y nuestra esperanza viva queda sellada en la tumba vacía.',
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
                'fr' => 'Pentecôte',
                'de' => 'Pfingsten',
                'es' => 'Pentecostés',
            ],
            'briefs'      => [
                'en' => 'The Spirit is poured out and the church is born — fire, wind, and bold witness to the nations.',
                'my' => 'သန့်ရှင်းသောဝိညာဉ်တော် သွန်းလောင်းခြင်းခံရပြီး အသင်းတော် ဖွဲ့စည်းတည်ထောင်ခြင်း ဖြစ်ပါ၏။',
                'td' => 'Kha Siangtho hong sung a, pawlpi piang hi; mei, huihpi, leh hangsanna tetti.',
                'fr' => "L'Esprit est répandu et l'Église naît : feu, vent et témoignage courageux aux nations.",
                'de' => 'Der Geist wird ausgegossen und die Kirche wird geboren: Feuer, Wind und mutiges Zeugnis für die Völker.',
                'es' => 'El Espíritu es derramado y nace la iglesia: fuego, viento y testimonio valiente a las naciones.',
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
                'fr' => 'Dimanche de la Réforme',
                'de' => 'Reformationssonntag',
                'es' => 'Domingo de la Reforma',
            ],
            'briefs'      => [
                'en' => 'Saved by grace through faith alone — we remember the recovery of the gospel and the open Word.',
                'my' => 'ယုံကြည်ခြင်းအားဖြင့် ကျေးဇူးတော်ကြောင့်သာ ကယ်တင်ခြင်း — ဧဝံဂေလိတရား ပြန်လည်ထွန်းကားလာခြင်းကို အောက်မေ့ပါ၏။',
                'td' => 'Upna tungtawn hehpihna in hotkhiatna — lungdamna thu kiphuankikna i phawk hi.',
                'fr' => "Sauvés par la grâce au moyen de la foi seule, nous nous souvenons de l'Évangile retrouvé et de la Parole ouverte.",
                'de' => 'Aus Gnade durch Glauben allein gerettet, erinnern wir uns an die Wiederentdeckung des Evangeliums und des offenen Wortes.',
                'es' => 'Salvos por gracia mediante la fe sola, recordamos la recuperación del evangelio y la Palabra abierta.',
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
                'fr' => "Premier dimanche de l'Avent",
                'de' => 'Erster Adventssonntag',
                'es' => 'Primer domingo de Adviento',
            ],
            'briefs'      => [
                'en' => 'We begin the season of waiting — hope kindled as we prepare our hearts for the coming of Christ.',
                'my' => 'စောင့်မျှော်ခြင်းကာလ စတင်ပါ၏။ ခရစ်တော် ကြွလာတော်မူခြင်းအတွက် နှလုံးသားကို ပြင်ဆင်ရင်း မျှော်လင့်ခြင်း ထွန်းလင်းပါ၏။',
                'td' => 'Ngakna hun i kipan; Christ hongpaina ading i lungtang kiginkholhna lametna hong tang hi.',
                'fr' => "Nous entrons dans le temps de l'attente : l'espérance s'allume tandis que nous préparons nos coeurs à la venue du Christ.",
                'de' => 'Wir beginnen die Zeit des Wartens: Hoffnung wird entzündet, während wir unsere Herzen auf das Kommen Christi vorbereiten.',
                'es' => 'Comenzamos el tiempo de espera: la esperanza se enciende mientras preparamos el corazón para la venida de Cristo.',
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
                'fr' => 'Fête des mères',
                'de' => 'Muttertag',
                'es' => 'Día de las Madres',
            ],
            'briefs'      => [
                'en' => 'We give thanks for mothers and the love that nurtures us, honoring them before the Lord today.',
                'my' => 'အမေများနှင့် ကျွန်ုပ်တို့ကို ပြုစုပျိုးထောင်သော မေတ္တာအတွက် ကျေးဇူးတင်ပါ၏။',
                'td' => 'Nu te leh amau itna ading lungdam i ko a, tu ni in Topa maiah i zahtak hi.',
                'fr' => "Nous rendons grâce pour les mères et pour l'amour qui nous nourrit, les honorant aujourd'hui devant le Seigneur.",
                'de' => 'Wir danken für Mütter und für die Liebe, die uns trägt, und ehren sie heute vor dem Herrn.',
                'es' => 'Damos gracias por las madres y por el amor que nos cuida, honrándolas hoy delante del Señor.',
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
                'fr' => 'Fête des pères',
                'de' => 'Vatertag',
                'es' => 'Día de los Padres',
            ],
            'briefs'      => [
                'en' => 'We honor fathers and the steady, sacrificial love that points us to our Father in heaven.',
                'my' => 'အဖေများနှင့် ကောင်းကင်ဘုံရှိ အဖခမည်းတော်ဆီသို့ ညွှန်ပြသော ခိုင်မြဲသည့် မေတ္တာအတွက် ဂုဏ်ပြုပါ၏။',
                'td' => 'Pa te leh vana i Pa lam hong lak a kician itna i zahtak hi.',
                'fr' => "Nous honorons les pères et l'amour fidèle et sacrificiel qui nous montre notre Père céleste.",
                'de' => 'Wir ehren Väter und die beständige, hingebungsvolle Liebe, die auf unseren Vater im Himmel weist.',
                'es' => 'Honramos a los padres y el amor firme y sacrificial que nos señala a nuestro Padre celestial.',
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
                'fr' => 'Journée des enfants',
                'de' => 'Kindertag',
                'es' => 'Día de la Niñez',
            ],
            'briefs'      => [
                'en' => 'Let the little children come — we celebrate and bless the youngest among us as a gift from God.',
                'my' => 'ကလေးသူငယ်တို့ ငါ့ထံသို့ လာကြစေ — ဘုရားသခင်၏ ဆုကျေးဇူးအဖြစ် ကလေးများကို ဂုဏ်ပြုကောင်းချီးပေးပါ၏။',
                'td' => 'Naupang te ka kiangah hong pai uhheh — Pasian letsong bangin naupang te i thupha hi.',
                'fr' => 'Laissez venir les petits enfants : nous célébrons et bénissons les plus jeunes parmi nous comme un don de Dieu.',
                'de' => 'Lasst die Kinder kommen: Wir feiern und segnen die Jüngsten unter uns als Gabe Gottes.',
                'es' => 'Dejen que los niños vengan: celebramos y bendecimos a los más pequeños como un regalo de Dios.',
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
                'fr' => 'Journée de la jeunesse',
                'de' => 'Jugendtag',
                'es' => 'Día de la Juventud',
            ],
            'briefs'      => [
                'en' => 'We commission the next generation — bold, faithful, and called to let no one despise their youth.',
                'my' => 'နောက်လူမျိုးဆက်ကို တာဝန်ပေးအပ်ပါ၏ — ရဲရင့်၍ သစ္စာရှိစွာ ဘုရားသခင်အတွက် အသက်ရှင်ကြစေ။',
                'td' => 'Khangthak suankhat te i sawl hi — hangsan, muanhuai, kuamah in tangvalna honmusit khол louhna.',
                'fr' => 'Nous envoyons la prochaine génération : courageuse, fidèle et appelée à ne laisser personne mépriser sa jeunesse.',
                'de' => 'Wir senden die nächste Generation aus: mutig, treu und berufen, sich von niemandem wegen ihrer Jugend verachten zu lassen.',
                'es' => 'Comisionamos a la próxima generación: valiente, fiel y llamada a no dejar que nadie menosprecie su juventud.',
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
                'fr' => "Dimanche d'action de grâce",
                'de' => 'Danksonntag',
                'es' => 'Domingo de Acción de Gracias',
            ],
            'briefs'      => [
                'en' => 'We count our blessings and give thanks in all things to the God from whom every good gift flows.',
                'my' => 'ကောင်းချီးများကို ရေတွက်၍ အရာရာ၌ ကျေးဇူးတော်ကို ချီးမွမ်းပါ၏။',
                'td' => 'Thupha te i sim a, na tengtengah letsong hoih a hong piakpa Pasian tungah lungdam i ko hi.',
                'fr' => 'Nous comptons nos bénédictions et rendons grâce en toutes choses au Dieu de qui vient tout don parfait.',
                'de' => 'Wir zählen unsere Segnungen und danken in allem dem Gott, von dem jede gute Gabe kommt.',
                'es' => 'Contamos nuestras bendiciones y damos gracias en todo al Dios de quien procede todo buen don.',
            ],
            'sermon_tags' => ['thanksgiving', 'gratitude', 'provision', 'contentment', 'praise'],
            'music_moods' => ['grateful', 'thankful', 'praise'],
            'region'      => null,
            'priority'    => 55,
        ],
    ],
];
