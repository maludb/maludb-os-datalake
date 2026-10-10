<?php
declare(strict_types=1);
/** Draws the Data Lake's brand images with GD (the logo, the abbreviated mark, the favicon, the PWA icons): php bin/make_brand_images.php. Rerun after changing the mark. */
$dir = dirname(__DIR__) . '/html/assets/images';
$font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
$indigo = [55, 88, 230];
function mark(int $size, bool $rounded = true): GdImage
{
    global $indigo;
    $im = imagecreatetruecolor($size, $size);
    imagesavealpha($im, true);
    imagealphablending($im, false);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagealphablending($im, true);
    $bg = imagecolorallocate($im, ...$indigo);
    $r = $rounded ? (int) round($size * 0.22) : 0;
    imagefilledrectangle($im, $r, 0, $size - 1 - $r, $size - 1, $bg);
    imagefilledrectangle($im, 0, $r, $size - 1, $size - 1 - $r, $bg);
    foreach ([[$r, $r], [$size - 1 - $r, $r], [$r, $size - 1 - $r], [$size - 1 - $r, $size - 1 - $r]] as [$cx, $cy]) {
        imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $bg);
    }
    // a database (the catalog's feather-database): a cylinder of three stacked discs — the lake's tables, one store
    $white = imagecolorallocate($im, 255, 255, 255);
    $cx = (int) round($size / 2);
    $w = (int) round($size * 0.56); $eh = max(4, (int) round($size * 0.16));
    $top = (int) round($size * 0.26); $bottom = (int) round($size * 0.74);
    imagefilledrectangle($im, $cx - (int) ($w / 2), $top, $cx + (int) ($w / 2), $bottom, $white);
    imagefilledellipse($im, $cx, $bottom, $w, $eh, $white);
    imagefilledellipse($im, $cx, $top, $w, $eh, $white);
    $t = max(1, (int) round($size * 0.035));
    foreach ([$top, (int) round(($top + $bottom) / 2 - $eh * 0.15), (int) round($bottom - $eh * 0.30)] as $i => $y) {
        if ($i === 0) {                                                        // the lid's rim
            imagesetthickness($im, $t);
            imagearc($im, $cx, $y, $w - 2 * $t, $eh - 2 * $t, 0, 360, $bg);
            continue;
        }
        imagesetthickness($im, $t);                                            // the bands between the discs
        imagearc($im, $cx, $y, $w, $eh, 0, 180, $bg);
    }
    imagesetthickness($im, 1);
    return $im;
}
foreach (['favicon.png' => 64, 'logo-abbr.png' => 128, 'apple-touch-icon.png' => 180, 'icon-192.png' => 192, 'icon-512.png' => 512] as $file => $size) {
    $im = mark($size, $file !== 'apple-touch-icon.png');
    if ($file === 'apple-touch-icon.png') {                                   // iOS rounds it itself: an opaque square
        $flat = imagecreatetruecolor($size, $size);
        imagefill($flat, 0, 0, imagecolorallocate($flat, ...$indigo));
        imagecopy($flat, $im, 0, 0, 0, 0, $size, $size);
        $im = $flat;
    }
    imagepng($im, $dir . '/' . $file);
}
// the full logo: the mark and the name
$w = 420; $h = 90;
$im = imagecreatetruecolor($w, $h);
imagesavealpha($im, true);
imagealphablending($im, false);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
imagealphablending($im, true);
$m = mark(70);
imagecopy($im, $m, 4, 10, 0, 0, 70, 70);
$dark = imagecolorallocate($im, 33, 37, 41);
imagettftext($im, 21, 0, 86, 55, $dark, $font, 'Data Lake');
imagepng($im, $dir . '/logo-full.png');
echo "brand images drawn in $dir\n";
