<?php

namespace App\Console\Commands;

use App\Support\Pwa;
use Illuminate\Console\Command;

/**
 * Draws ScholarZim's home-screen icons from the same geometry as the
 * <x-brand-mark /> component.
 *
 * The icons are committed to the repository - a deploy must not have to run
 * anything to get them - but they are generated rather than hand-drawn so the
 * home-screen icon cannot drift away from the mark in the navigation bar. Change
 * the paths in resources/views/components/brand-mark.blade.php and re-run this;
 * anything else and the two are only related by memory.
 *
 * The treatment is white-on-primary rather than the primary-on-transparent used
 * in the page header. That is not a second identity: it is the same mark in the
 * `tone="light"` variant the auth panel already uses, on the theme colour the
 * manifest names. A transparent icon is painted onto whatever the launcher feels
 * like using, which on a dark home screen loses the mark entirely.
 */
class GeneratePwaIcons extends Command
{
    protected $signature = 'scholarzim:pwa-icons';

    protected $description = "Regenerate the PWA home-screen icons from the ScholarZim brand mark";

    /**
     * The brand mark's own coordinate space - viewBox="0 0 32 32" in
     * components/brand-mark.blade.php. Every coordinate below is read straight
     * off that file, so the two stay comparable by eye.
     */
    private const VIEWBOX = 32.0;

    /** `M16 5 30 12 16 19 2 12z` - the mortarboard, a filled quadrilateral. */
    private const CAP = [[16, 5], [30, 12], [16, 19], [2, 12]];

    /**
     * `M8 15.5V22c0 2.2 3.6 4 8 4s8-1.8 8-4v-6.5`, stroked at 2.4 with round
     * caps: the two uprights and the curve joining them under the cap.
     */
    private const STROKE_WIDTH = 2.4;

    /** The stroke carries opacity=".55" in the component. */
    private const STROKE_OPACITY = 0.55;

    /**
     * Supersampling factor. GD antialiases lines but not filled polygons, so the
     * mark is drawn at 4x and resampled down - which is also what softens the
     * circles the curve is stamped from into a continuous stroke.
     */
    private const SUPERSAMPLE = 4;

    /**
     * Padding as a fraction of the icon's width, per output.
     *
     * A maskable icon is cropped by the launcher to whatever shape the platform
     * uses - circle, squircle, teardrop - and only the middle 80% is guaranteed
     * to survive. 0.28 keeps the mark inside that safe zone with room to spare;
     * the plain icons use a tighter frame because nothing crops them.
     */
    private const OUTPUTS = [
        ['assets/img/icon-192.png', 192, 0.16],
        ['assets/img/icon-512.png', 512, 0.16],
        ['assets/img/icon-maskable-512.png', 512, 0.28],
        ['assets/img/apple-touch-icon.png', 180, 0.16],
    ];

    public function handle(): int
    {
        if (! extension_loaded('gd')) {
            $this->error('ext-gd is required to draw the icons.');

            return self::FAILURE;
        }

        foreach (self::OUTPUTS as [$path, $size, $padding]) {
            $image = $this->draw($size, $padding);
            $target = public_path($path);

            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }

            imagepng($image, $target, 9);
            imagedestroy($image);

            $this->line(sprintf('  %-44s %dx%d', $path, $size, $size));
        }

        $this->info('Icons regenerated from the brand mark.');

        return self::SUCCESS;
    }

    private function draw(int $size, float $paddingRatio): \GdImage
    {
        $canvas = $size * self::SUPERSAMPLE;
        $image = imagecreatetruecolor($canvas, $canvas);

        [$br, $bg, $bb] = $this->rgb(Pwa::THEME_COLOR);
        imagefilledrectangle($image, 0, 0, $canvas, $canvas, imagecolorallocate($image, $br, $bg, $bb));

        $white = imagecolorallocate($image, 255, 255, 255);

        // The stroke's opacity is flattened against the background here rather
        // than drawn with alpha: the curve is stamped from overlapping circles,
        // and translucent stamps would darken every point where two overlap.
        $stroke = imagecolorallocate(
            $image,
            (int) round(255 * self::STROKE_OPACITY + $br * (1 - self::STROKE_OPACITY)),
            (int) round(255 * self::STROKE_OPACITY + $bg * (1 - self::STROKE_OPACITY)),
            (int) round(255 * self::STROKE_OPACITY + $bb * (1 - self::STROKE_OPACITY)),
        );

        $inset = $canvas * $paddingRatio;
        $scale = ($canvas - 2 * $inset) / self::VIEWBOX;
        $project = fn (float $x, float $y): array => [$inset + $x * $scale, $inset + $y * $scale];

        $polygon = [];
        foreach (self::CAP as [$x, $y]) {
            [$px, $py] = $project($x, $y);
            $polygon[] = (int) round($px);
            $polygon[] = (int) round($py);
        }
        imagefilledpolygon($image, $polygon, $white);

        $radius = self::STROKE_WIDTH * $scale / 2;
        foreach ($this->strokePath() as [$x, $y]) {
            [$px, $py] = $project($x, $y);
            imagefilledellipse($image, (int) round($px), (int) round($py), (int) round($radius * 2), (int) round($radius * 2), $stroke);
        }

        $out = imagecreatetruecolor($size, $size);
        imagecopyresampled($out, $image, 0, 0, 0, 0, $size, $size, $canvas, $canvas);
        imagedestroy($image);

        return $out;
    }

    /**
     * The stroked path, sampled densely enough that consecutive stamps overlap.
     *
     * @return list<array{float, float}>
     */
    private function strokePath(): array
    {
        $points = [];

        // V22 from (8, 15.5), and the mirrored upright back up to (24, 15.5).
        for ($t = 0.0; $t <= 1.0; $t += 0.004) {
            $points[] = [8.0, 15.5 + $t * (22.0 - 15.5)];
            $points[] = [24.0, 15.5 + $t * (22.0 - 15.5)];
        }

        // c0 2.2 3.6 4 8 4  - relative to (8, 22).
        $points = array_merge($points, $this->bezier([8, 22], [8, 24.2], [11.6, 26], [16, 26]));

        // s8-1.8 8-4 - the smooth continuation, its first control point the
        // reflection of the previous one about (16, 26).
        $points = array_merge($points, $this->bezier([16, 26], [20.4, 26], [24, 24.2], [24, 22]));

        return $points;
    }

    /**
     * @param  array{float|int, float|int}  $p0
     * @param  array{float|int, float|int}  $p1
     * @param  array{float|int, float|int}  $p2
     * @param  array{float|int, float|int}  $p3
     * @return list<array{float, float}>
     */
    private function bezier(array $p0, array $p1, array $p2, array $p3): array
    {
        $points = [];

        for ($t = 0.0; $t <= 1.0; $t += 0.002) {
            $u = 1 - $t;
            $points[] = [
                $u ** 3 * $p0[0] + 3 * $u ** 2 * $t * $p1[0] + 3 * $u * $t ** 2 * $p2[0] + $t ** 3 * $p3[0],
                $u ** 3 * $p0[1] + 3 * $u ** 2 * $t * $p1[1] + 3 * $u * $t ** 2 * $p2[1] + $t ** 3 * $p3[1],
            ];
        }

        return $points;
    }

    /** @return array{int, int, int} */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
