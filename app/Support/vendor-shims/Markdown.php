<?php
declare(strict_types=1);

/**
 * File: app/Support/vendor-shims/Markdown.php
 *
 * Purpose:
 *   Minimal Markdown-to-HTML renderer, standing in for erusev/parsedown.
 *   Supports the subset needed for admin-authored help text: headings, bold,
 *   italic, inline code, links, unordered/ordered lists, and paragraphs.
 *   Output is passed through the HtmlSanitizer whitelist before use.
 *
 * Used by:
 *   - Help/tooltip text rendering (optional)
 *
 * @package App\Support\Vendor
 */

namespace App\Support\Vendor;

/**
 * Class: Markdown
 *
 * Purpose: Render a small, safe subset of Markdown to HTML.
 */
final class Markdown
{
    /**
     * Render a Markdown string to sanitized HTML.
     *
     * @param string $markdown Source text.
     * @return string Safe HTML.
     */
    public static function render(string $markdown): string
    {
        $html = self::toHtml($markdown);
        return HtmlSanitizer::clean($html);
    }

    /**
     * Convert Markdown to raw HTML (pre-sanitization).
     *
     * @param string $markdown Source text.
     * @return string HTML.
     */
    private static function toHtml(string $markdown): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $out = [];
        $inList = false;
        $listType = '';

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                if ($inList) { $out[] = "</$listType>"; $inList = false; }
                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.*)$/', $trimmed, $m) === 1) {
                if ($inList) { $out[] = "</$listType>"; $inList = false; }
                $level = strlen($m[1]);
                $out[] = "<h{$level}>" . self::inline($m[2]) . "</h{$level}>";
                continue;
            }
            if (preg_match('/^[-*]\s+(.*)$/', $trimmed, $m) === 1) {
                if (!$inList || $listType !== 'ul') {
                    if ($inList) { $out[] = "</$listType>"; }
                    $out[] = '<ul>';
                    $inList = true;
                    $listType = 'ul';
                }
                $out[] = '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }
            if (preg_match('/^\d+\.\s+(.*)$/', $trimmed, $m) === 1) {
                if (!$inList || $listType !== 'ol') {
                    if ($inList) { $out[] = "</$listType>"; }
                    $out[] = '<ol>';
                    $inList = true;
                    $listType = 'ol';
                }
                $out[] = '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }
            if ($inList) { $out[] = "</$listType>"; $inList = false; }
            $out[] = '<p>' . self::inline($trimmed) . '</p>';
        }
        if ($inList) { $out[] = "</$listType>"; }
        return implode("\n", $out);
    }

    /**
     * Render inline Markdown constructs.
     *
     * @param string $text Source text.
     * @return string HTML.
     */
    private static function inline(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text) ?? $text;
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', '<a href="$2">$1</a>', $text) ?? $text;
        return $text;
    }
}
