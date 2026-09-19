<?php
declare(strict_types=1);

/**
 * File: app/Support/vendor-shims/HtmlSanitizer.php
 *
 * Purpose:
 *   Whitelist-based HTML sanitizer, standing in for ezyang/htmlpurifier.
 *   Strips scripts, event handlers, and dangerous URLs from admin-authored
 *   rich text and from uploaded SVG assets.
 *
 * Used by:
 *   - MediaService (SVG sanitization)
 *   - Any rich-HTML field (help text, descriptions)
 *
 * @package App\Support\Vendor
 */

namespace App\Support\Vendor;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Class: HtmlSanitizer
 *
 * Purpose: Sanitize untrusted HTML and SVG down to a safe tag/attribute whitelist.
 */
final class HtmlSanitizer
{
    /** @var string[] Allowed HTML tags. */
    private const ALLOWED_TAGS = ['p','br','strong','b','em','i','u','s','ul','ol','li','a','blockquote','code','pre','h1','h2','h3','h4','h5','h6','table','thead','tbody','tr','th','td','span','div','hr','img'];

    /** @var string[] Allowed attributes per any tag. */
    private const ALLOWED_ATTRS = ['href','title','alt','src','class','colspan','rowspan','dir','lang'];

    /** @var string[] Allowed SVG tags. */
    private const ALLOWED_SVG = ['svg','g','path','rect','circle','ellipse','line','polyline','polygon','text','tspan','defs','linearGradient','radialGradient','stop','clipPath','use','title','desc'];

    /**
     * Sanitize an HTML fragment.
     *
     * @param string $html Untrusted HTML.
     * @return string Safe HTML.
     */
    public static function clean(string $html): string
    {
        if ($html === '') { return ''; }
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $root = $doc->getElementById('__root');
        if ($root === null) { return ''; }

        self::walkHtml($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child) ?: '';
        }
        return $out;
    }

    /**
     * Recursively strip disallowed HTML nodes and attributes.
     *
     * @param DOMNode $node Node to walk.
     * @return void
     */
    private static function walkHtml(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                    $node->removeChild($child);
                    continue;
                }
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $name = strtolower($attr->nodeName);
                    if (str_starts_with($name, 'on') || !in_array($name, self::ALLOWED_ATTRS, true)) {
                        $child->removeAttribute($attr->nodeName);
                        continue;
                    }
                    if (in_array($name, ['href', 'src'], true) && !self::safeUrl($attr->value)) {
                        $child->removeAttribute($attr->nodeName);
                    }
                }
                self::walkHtml($child);
            }
        }
    }

    /**
     * Sanitize an SVG document to a narrow whitelist.
     *
     * @param string $svg Raw SVG markup.
     * @return string|null Safe SVG, or null when it cannot be sanitised.
     */
    public static function cleanSvg(string $svg): ?string
    {
        if (stripos($svg, '<svg') === false) { return null; }
        if (stripos($svg, '<script') !== false) { return null; }
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        if ($doc->loadXML($svg) === false) {
            libxml_clear_errors();
            return null;
        }
        libxml_clear_errors();
        $root = $doc->documentElement;
        if ($root === null || strtolower($root->tagName) !== 'svg') { return null; }
        self::walkSvg($root);
        return $doc->saveXML($root) ?: null;
    }

    /**
     * Recursively strip disallowed SVG nodes and attributes.
     *
     * @param DOMNode $node Node to walk.
     * @return void
     */
    private static function walkSvg(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = $child->tagName;
                if (!in_array($tag, self::ALLOWED_SVG, true) && !in_array(strtolower($tag), self::ALLOWED_SVG, true)) {
                    $node->removeChild($child);
                    continue;
                }
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $name = strtolower($attr->nodeName);
                    if (str_starts_with($name, 'on') || $name === 'href') {
                        $child->removeAttribute($attr->nodeName);
                    }
                }
                self::walkSvg($child);
            }
        }
    }

    /**
     * Determine whether a URL scheme is safe.
     *
     * @param string $url Candidate URL.
     * @return bool
     */
    private static function safeUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') { return false; }
        if (str_starts_with($url, '/') || str_starts_with($url, '#') || str_starts_with($url, '?')) { return true; }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https', 'mailto', 'tel', 'data'], true)
            && !str_starts_with(strtolower($url), 'data:text/html');
    }
}
