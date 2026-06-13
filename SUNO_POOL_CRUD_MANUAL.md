# Suno Song Pool Manual CRUD

Manual operations for `music_tracks` (the Suno reuse pool).

Use this when you want to add, inspect, fix, or remove pool rows directly.

## 0) Before you edit

```bash
# Optional but recommended: temporarily disable reuse while editing
cd /opt/ai-church/backend
php artisan tinker --execute="App\\Models\\Setting::set('music_reuse','0');"

# Quick backup of pool table
mysqldump -u ai_church -p ai_church music_tracks > /tmp/music_tracks_backup_$(date +%F_%H%M%S).sql
```

Pool schema in use:
- `mood`
- `language` (`en` | `my` | `td`)
- `provider_ref` (UNIQUE, Suno task id)
- `storage_key` (raw object key)
- `title`
- `lyrics`
- `source` (should be `suno`)

## 1) CREATE

## 1.1 Create with MySQL

```sql
INSERT INTO music_tracks
(mood, language, provider_ref, storage_key, title, lyrics, source, created_at, updated_at)
VALUES
('Anxious', 'td', 'manual_suno_20260612_001', 'worship/manual_suno_20260612_001.mp3',
 'Topa ah ka muanna',
 'Topa aw, ka lungtang sungah hong om in... (full lyrics)',
 'suno', NOW(), NOW());
```

## 1.2 Create with Laravel Tinker

```bash
cd /opt/ai-church/backend
php artisan tinker --execute="App\\Models\\MusicTrack::create([
'mood'=>'Anxious',
'language'=>'td',
'provider_ref'=>'manual_suno_20260612_001',
'storage_key'=>'worship/manual_suno_20260612_001.mp3',
'title'=>'Topa ah ka muanna',
'lyrics'=>'Topa aw, ka lungtang sungah hong om in... (full lyrics)',
'source'=>'suno'
]);"
```

## 2) READ

## 2.1 List latest rows

```sql
SELECT id, mood, language, provider_ref, title, source, created_at
FROM music_tracks
ORDER BY id DESC
LIMIT 50;
```

## 2.2 Find by mood + language

```sql
SELECT id, mood, language, provider_ref, title
FROM music_tracks
WHERE mood='Anxious' AND language='td'
ORDER BY id DESC;
```

## 2.3 Find suspicious rows

```sql
-- Missing lyrics (won't be reused)
SELECT id, mood, language, provider_ref
FROM music_tracks
WHERE lyrics IS NULL OR lyrics='';

-- Wrong source label
SELECT id, mood, language, provider_ref, source
FROM music_tracks
WHERE source <> 'suno' OR source IS NULL;
```

## 3) UPDATE

## 3.1 Fix title/lyrics/language

```sql
UPDATE music_tracks
SET language='td',
    title='Topa ah ka muanna (fixed)',
    lyrics='(correct Tedim/Zolai lyrics here)',
    updated_at=NOW()
WHERE provider_ref='manual_suno_20260612_001';
```

## 3.2 Replace storage key

```sql
UPDATE music_tracks
SET storage_key='worship/new_object_key.mp3', updated_at=NOW()
WHERE id=123;
```

## 3.3 Force source to suno

```sql
UPDATE music_tracks
SET source='suno', updated_at=NOW()
WHERE id=123;
```

## 4) DELETE

## 4.1 Delete one row by provider_ref

```sql
DELETE FROM music_tracks
WHERE provider_ref='manual_suno_20260612_001'
LIMIT 1;
```

## 4.2 Delete all bad rows for one mood/language (example)

```sql
DELETE FROM music_tracks
WHERE mood='Anxious'
  AND language='td'
  AND (lyrics IS NULL OR lyrics='');
```

## 5) Post-edit validation

```sql
-- Confirm no duplicate provider_ref (should always be unique)
SELECT provider_ref, COUNT(*) c
FROM music_tracks
GROUP BY provider_ref
HAVING c > 1;

-- Confirm reusable rows exist for target mood/language
SELECT COUNT(*) AS reusable_rows
FROM music_tracks
WHERE mood='Anxious'
  AND language='td'
  AND source='suno'
  AND lyrics IS NOT NULL
  AND lyrics <> '';
```

## 6) Re-enable pool

```bash
cd /opt/ai-church/backend
php artisan tinker --execute="App\\Models\\Setting::set('music_reuse','1');"
```

## 7) Operational notes

- Reuse selection is language + mood keyed and also checks lyric language in code.
- For Tedim (`td`), avoid Mizo/Falam/Haka words in lyrics (`Pathian`, `Lalpa`, `Isua`, etc.).
- `storage_key` must be the raw object key, not a presigned URL.
- Deleting a row only removes DB metadata; it does not delete the audio object from storage.
