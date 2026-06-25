<?php

/*
|--------------------------------------------------------------------------
| Special-Day auto YouTube songs (hardcoded catalog)
|--------------------------------------------------------------------------
|
| Source of truth for the "Auto YouTube" mode of the Special Day page
| (FathersDayController). When the admin turns that mode ON (and the manual
| MV song library is OFF — the two modes are mutually exclusive), the public
| page resolves the CURRENT active Special Sunday (via SpecialSundayResolver)
| and plays the YouTube songs listed here for that observance's `key`, with a
| share button only — no video is created.
|
| The map is keyed by the SAME `key` used in config/special_sundays.php:
|   palm_sunday, easter_sunday, pentecost, reformation_sunday, advent_first,
|   mothers_day, fathers_day, childrens_day, youth_day, thanksgiving_sunday.
|
| Each entry: ['title' => '<display title>', 'youtube_id' => '<11-char id>'].
| `youtube_id` is the part after `watch?v=` (or after `youtu.be/`). Entries
| with an empty/invalid id are skipped, so a day with no usable songs simply
| falls back to "not available" rather than showing a broken player.
|
| ⚠ VERIFY the ids below before relying on them in production — they are
| starter suggestions of well-known public worship uploads and may change or
| be region-restricted. Editing this file is the supported way to curate the
| auto songs; no migration or DB write is involved.
*/

return [

    'palm_sunday' => [
        ['title' => 'Hosanna (Praise Is Rising) — Paul Baloche', 'youtube_id' => ''],
        ['title' => 'All Glory, Laud, and Honor', 'youtube_id' => ''],
    ],

    'easter_sunday' => [
        ['title' => 'Christ the Lord Is Risen Today', 'youtube_id' => ''],
        ['title' => 'In Christ Alone', 'youtube_id' => ''],
    ],

    'pentecost' => [
        ['title' => 'Holy Spirit (You Are Welcome Here)', 'youtube_id' => ''],
        ['title' => 'Come Holy Spirit', 'youtube_id' => ''],
    ],

    'reformation_sunday' => [
        ['title' => 'A Mighty Fortress Is Our God', 'youtube_id' => ''],
    ],

    'advent_first' => [
        ['title' => 'O Come, O Come, Emmanuel', 'youtube_id' => ''],
        ['title' => 'Come Thou Long Expected Jesus', 'youtube_id' => ''],
    ],

    'mothers_day' => [
        ['title' => 'A Mother\'s Prayer', 'youtube_id' => ''],
    ],

    'fathers_day' => [
        ['title' => 'Good Good Father — Chris Tomlin', 'youtube_id' => ''],
        ['title' => 'How Deep the Father\'s Love for Us', 'youtube_id' => ''],
    ],

    'childrens_day' => [
        ['title' => 'Jesus Loves Me', 'youtube_id' => ''],
    ],

    'youth_day' => [
        ['title' => 'Build My Life', 'youtube_id' => ''],
    ],

    'thanksgiving_sunday' => [
        ['title' => 'Give Thanks (with a Grateful Heart)', 'youtube_id' => ''],
        ['title' => '10,000 Reasons (Bless the Lord)', 'youtube_id' => ''],
    ],
];
