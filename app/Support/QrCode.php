<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class QrCode
{
    /**
     * Inline SVG markup of a QR code for the given text (sharp at any print size).
     */
    public static function svg(string $text): string
    {
        $renderer = new ImageRenderer(new RendererStyle(240, 0), new SvgImageBackEnd);
        $svg = (new Writer($renderer))->writeString($text);

        return trim((string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg));
    }
}
