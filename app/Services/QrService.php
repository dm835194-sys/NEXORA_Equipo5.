<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class QrService
{
    public function svg(string $text): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle(280, 3), new SvgImageBackEnd)))->writeString($text);
    }
}
