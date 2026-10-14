<?php

namespace App\Services\Branding;

use DOMDocument;
use DOMElement;

/**
 * Makes an uploaded SVG safe to serve from the public disk.
 *
 * An SVG opened directly in the browser runs any script it carries, so a logo
 * is rebuilt from an allowlist: drawing elements and presentation attributes
 * only. Scripts, event handlers, <foreignObject>, external or javascript:
 * links and DOCTYPE/entity declarations never survive. CSS is kept (design
 * tools colour shapes through a <style> block) unless it imports or loads
 * anything from outside the file.
 */
class SvgSanitizer
{
    /** @var array<int, string> */
    private const ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'style',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textPath',
        'linearGradient', 'radialGradient', 'stop', 'clipPath', 'mask', 'pattern',
    ];

    /** @var array<int, string> */
    private const ATTRIBUTES = [
        'id', 'class', 'style', 'type', 'data-name', 'viewBox', 'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2',
        'cx', 'cy', 'r', 'rx', 'ry', 'd', 'points', 'transform', 'preserveAspectRatio',
        'fill', 'fill-opacity', 'fill-rule', 'clip-rule', 'clip-path', 'mask', 'opacity',
        'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit',
        'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity',
        'offset', 'stop-color', 'stop-opacity', 'gradientUnits', 'gradientTransform',
        'spreadMethod', 'fx', 'fy', 'patternUnits', 'patternTransform', 'clipPathUnits', 'maskUnits',
        'font-family', 'font-size', 'font-weight', 'font-style', 'letter-spacing',
        'text-anchor', 'dominant-baseline', 'dx', 'dy', 'href', 'xlink:href', 'xmlns', 'xmlns:xlink', 'version',
    ];

    /**
     * The cleaned markup, or null when the file is not a usable SVG.
     */
    public function clean(string $svg): ?string
    {
        // Entities and DOCTYPEs are how XXE and billion-laughs payloads arrive.
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $svg)) {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($svg, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->documentElement;

        if (! $loaded || ! $root instanceof DOMElement || $root->localName !== 'svg') {
            return null;
        }

        $this->scrub($root);

        return $document->saveXML($root) ?: null;
    }

    private function scrub(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            if (! $this->keepAttribute($attribute->nodeName, $attribute->nodeValue ?? '')) {
                $element->removeAttributeNode($attribute);
            }
        }

        // A <style> block keeps its CSS only when nothing in it reaches out.
        if ($element->localName === 'style') {
            $css = $element->textContent;
            $element->textContent = '';

            if ($this->isSafeCss($css)) {
                $element->appendChild($element->ownerDocument->createTextNode($css));
            }

            return;
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                if (in_array($child->localName, self::ELEMENTS, true)) {
                    $this->scrub($child);
                } else {
                    $element->removeChild($child);
                }
            } elseif ($child->nodeType !== XML_TEXT_NODE) {
                // Comments, processing instructions, CDATA outside <style>.
                $element->removeChild($child);
            }
        }
    }

    private function keepAttribute(string $name, string $value): bool
    {
        if (! in_array($name, self::ATTRIBUTES, true)) {
            return false;
        }

        // Links may only point inside the document (gradients, <use>).
        if (in_array($name, ['href', 'xlink:href'], true)) {
            return str_starts_with(trim($value), '#');
        }

        return $this->isSafeCss($value);
    }

    /**
     * No script URLs, no imports, and url() only for in-file references.
     */
    private function isSafeCss(string $value): bool
    {
        $compact = strtolower(preg_replace('/\s+/', '', $value) ?? '');

        return ! str_contains($compact, 'javascript:')
            && ! str_contains($compact, '@import')
            && ! str_contains($compact, 'expression(')
            && ! preg_match('/url\((?!["\']?#)/', $compact);
    }
}
