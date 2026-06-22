<?php

namespace App\Services;

use App\Models\ChatSession;

/**
 * Renders a session (or the whole journal) to a downloadable document. Markdown and
 * JSON are produced inline; DOCX is a real (minimal, valid) OOXML zip; PDF is a
 * minimal single-stream PDF. No external service is contacted — export is private.
 *
 * Each export() returns ['filename', 'mime', 'body'] ready to stream as a download.
 */
class HistoryExportService
{
    public const FORMATS = ['md', 'json', 'pdf', 'docx'];

    /** @param ChatSession|ChatSession[] $sessions */
    public function export($sessions, string $format): array
    {
        $sessions = is_iterable($sessions) ? $sessions : [$sessions];
        $format = in_array($format, self::FORMATS, true) ? $format : 'md';
        $base = count($sessions) === 1 ? $this->slug(reset($sessions)) : 'spiritual-journal';

        return match ($format) {
            'json'  => $this->file($base, 'json', 'application/json', $this->toJson($sessions)),
            'pdf'   => $this->file($base, 'pdf', 'application/pdf', $this->toPdf($sessions)),
            'docx'  => $this->file($base, 'docx',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        $this->toDocx($sessions)),
            default => $this->file($base, 'md', 'text/markdown', $this->toMarkdown($sessions)),
        };
    }

    private function file(string $base, string $ext, string $mime, string $body): array
    {
        return ['filename' => "{$base}.{$ext}", 'mime' => $mime, 'body' => $body];
    }

    /** @param iterable<ChatSession> $sessions */
    public function toMarkdown(iterable $sessions): string
    {
        $out = "# Spiritual Journal\n\n";
        foreach ($sessions as $s) {
            $out .= "## {$s->title}\n\n";
            $out .= "- **Type:** {$s->session_type}\n";
            $out .= '- **Date:** ' . optional($s->started_at)->toDayDateTimeString() . "\n";
            if ($s->mood)     { $out .= "- **Mood:** {$s->mood}\n"; }
            if ($s->language) { $out .= "- **Language:** {$s->language}\n"; }
            $tags = $s->tags->pluck('tag')->implode(', ');
            if ($tags !== '') { $out .= "- **Tags:** {$tags}\n"; }
            $out .= "\n";
            if ($s->summary) { $out .= "> {$s->summary}\n\n"; }
            foreach ($s->messages as $m) {
                $who = $m->sender === 'user' ? 'You' : ucfirst($m->sender);
                $out .= "**{$who}:** {$m->content}\n\n";
            }
            $out .= "---\n\n";
        }

        return $out;
    }

    /** @param iterable<ChatSession> $sessions */
    public function toJson(iterable $sessions): string
    {
        $data = [];
        foreach ($sessions as $s) {
            $data[] = [
                'id'         => $s->id,
                'type'       => $s->session_type,
                'title'      => $s->title,
                'summary'    => $s->summary,
                'mood'       => $s->mood,
                'language'   => $s->language,
                'started_at' => optional($s->started_at)->toIso8601String(),
                'tags'       => $s->tags->pluck('tag')->all(),
                'messages'   => $s->messages->map(fn ($m) => [
                    'sender'     => $m->sender,
                    'type'       => $m->message_type,
                    'content'    => $m->content,
                    'created_at' => optional($m->created_at)->toIso8601String(),
                ])->all(),
            ];
        }

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Minimal valid OOXML .docx (a zip of the required parts). */
    private function toDocx(iterable $sessions): string
    {
        $paras = '';
        $p = fn (string $text, bool $bold = false) =>
            '<w:p><w:r>' . ($bold ? '<w:rPr><w:b/></w:rPr>' : '')
            . '<w:t xml:space="preserve">' . htmlspecialchars($text, ENT_XML1) . '</w:t></w:r></w:p>';

        $paras .= $p('Spiritual Journal', true);
        foreach ($sessions as $s) {
            $paras .= $p((string) $s->title, true);
            $paras .= $p($s->session_type . ' · ' . optional($s->started_at)->toDayDateTimeString());
            if ($s->summary) { $paras .= $p($s->summary); }
            foreach ($s->messages as $m) {
                $who = $m->sender === 'user' ? 'You' : ucfirst($m->sender);
                $paras .= $p("{$who}: {$m->content}");
            }
        }

        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . "<w:body>{$paras}</w:body></w:document>";

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';

        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('word/document.xml', $document);
        $zip->close();
        $body = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $body;
    }

    /** Minimal single-stream PDF (Helvetica, text only, wrapped). */
    private function toPdf(iterable $sessions): string
    {
        $lines = ['Spiritual Journal', ''];
        foreach ($sessions as $s) {
            $lines[] = (string) $s->title;
            $lines[] = $s->session_type . ' - ' . optional($s->started_at)->toDayDateTimeString();
            if ($s->summary) { $lines = array_merge($lines, $this->wrap($s->summary)); }
            foreach ($s->messages as $m) {
                $who = $m->sender === 'user' ? 'You' : ucfirst($m->sender);
                $lines = array_merge($lines, $this->wrap("{$who}: {$m->content}"));
            }
            $lines[] = '';
        }

        $y = 780;
        $content = "BT /F1 11 Tf 50 {$y} Td 14 TL";
        foreach ($lines as $line) {
            $esc = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
            $content .= " (" . $esc . ") Tj T*";
        }
        $content .= ' ET';

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $obj) {
            $offsets[$i] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $obj . "\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }
        $pdf .= "trailer << /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";

        return $pdf;
    }

    /** @return string[] */
    private function wrap(string $text, int $width = 90): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));

        return $text === '' ? [''] : explode("\n", wordwrap($text, $width, "\n", true));
    }

    private function slug(ChatSession $s): string
    {
        return \Illuminate\Support\Str::slug((string) ($s->title ?: $s->session_type)) ?: 'session';
    }
}
