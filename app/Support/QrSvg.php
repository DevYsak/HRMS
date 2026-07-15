<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * QR codes as SVG data URIs, sized for embedding in DomPDF documents.
 *
 * Uses bacon/bacon-qr-code, which is already in the tree as Fortify's 2FA
 * dependency — no extra package. Returns null on any failure so a broken QR
 * can never take a payslip render down with it.
 */
class QrSvg
{
    public static function dataUri(string $text, int $size = 120): ?string
    {
        try {
            $renderer = new ImageRenderer(new RendererStyle($size, 0), new SvgImageBackEnd);
            $svg = (new Writer($renderer))->writeString($text);

            return 'data:image/svg+xml;base64,'.base64_encode($svg);
        } catch (\Throwable) {
            return null;
        }
    }
}
