<?php

namespace App\Support;

/**
 * The two conversions a rich-text field needs at its edges.
 *
 * The Individual Counseling assessment fields moved from a plain textarea to
 * the rich editor, which stores HTML. Nothing was migrated: a record written
 * before the change (or by the spreadsheet import, which still writes plain
 * text) holds the counsellor's prose with real line breaks in it, and the
 * editor would read those breaks as spaces and flatten the note into one
 * paragraph the first time the record was opened. toHtml() gives the editor
 * one paragraph per line instead, and only for a value that is not HTML
 * already, so the stored data is never touched until the record is saved.
 *
 * toPlain() is the other direction, for the spreadsheet and the PDF, which
 * print text and would otherwise show the tags. Lists come out as one "- "
 * line per item; bold and italic are dropped, the words are kept.
 *
 * Both leave a value alone unless it clearly is the other kind, so a plain
 * note that happens to contain "<" (a MUAC reading written as "<115") is
 * never mistaken for markup and eaten by the tag stripper.
 */
final class RichText
{
    /** A tag the editor writes. A value with none of these is plain text. */
    private const TAG = '/<\/?(?:p|br|ul|ol|li|strong|em|b|i|u|s|h[1-6]|blockquote|a|span|div|pre|code)\b[^>]*>/i';

    public static function isHtml(?string $value): bool
    {
        return filled($value) && preg_match(self::TAG, $value) === 1;
    }

    /**
     * Whether a value holds no words at all: null, "", or the empty
     * paragraph the editor produces when nothing was typed into it.
     */
    public static function isBlank(?string $value): bool
    {
        return blank($value) || trim((string) self::toPlain($value)) === '';
    }

    /**
     * Plain text as HTML paragraphs, one per line; HTML unchanged.
     */
    public static function toHtml(?string $value): ?string
    {
        if (blank($value) || self::isHtml($value)) {
            return $value;
        }

        $lines = preg_split('/\R/u', $value);

        return implode('', array_map(fn (string $line): string => '<p>' . e($line) . '</p>', $lines));
    }

    /**
     * HTML as plain text with the line structure kept; plain text unchanged.
     */
    public static function toPlain(?string $value): ?string
    {
        if (blank($value) || ! self::isHtml($value)) {
            return $value;
        }

        $text = $value;

        // A list item wraps its text in a paragraph of its own; fold that
        // closing pair so an item does not end in two line breaks.
        $text = preg_replace('/<\/p>\s*<\/li>/i', '</li>', $text);
        $text = preg_replace('/<li\b[^>]*>\s*(?:<p\b[^>]*>)?/i', '- ', $text);

        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/(?:p|li|div|h[1-6]|blockquote|pre)>\s*/i', "\n", $text);

        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // The editor writes a non-breaking space for a deliberate double
        // space; a spreadsheet cell has no use for the distinction.
        $text = str_replace("\u{A0}", ' ', $text);
        $text = preg_replace('/[ \t]+\n/', "\n", $text);

        return trim($text);
    }

    /**
     * toHtml() over the named keys of a form data array, for the page and
     * repeater hooks that run before the editor is handed its value.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function hydrate(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (is_string($data[$key] ?? null)) {
                $data[$key] = self::toHtml($data[$key]);
            }
        }

        return $data;
    }
}
