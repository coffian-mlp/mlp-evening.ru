<?php

namespace LLM;

/** Bounded GIF demux/compositor. GD decodes individual frames; no external binaries or network. */
final class GifStoryboard {
    public const MAX_BYTES = 33554432;
    private const MAX_FRAMES = 1000;
    private const MAX_DECODED_PIXELS = 300000000;
    private const TIME_NS = 2000000000;

    /** @return ?array{jpeg: string, frames: int} null means keep the legacy first-frame path. */
    public static function render(string $bytes): ?array {
        $deadline = hrtime(true) + self::TIME_NS;
        try {
            if (strlen($bytes) > self::MAX_BYTES || !function_exists('imagecreatefromstring')) return null;
            [$w, $h, $global, $background, $frames] = self::parse($bytes, $deadline);
            $count = count($frames);
            if ($count < 2) return null;
            $samples = self::samples($frames);
            $cells = count($samples);
            $grid = $cells <= 4 ? 2 : 3;
            $scale = min(512 / max($w, $h), max(1, 32 / min($w, $h)));
            $dw = max(1, (int)round($w * $scale));
            $dh = max(1, (int)round($h * $scale));
            $cw = max(32, $dw);
            $ch = max(32, $dh);
            $maxFrame = max(array_map(fn($f) => $f['w'] * $f['h'], $frames));
            $maxEncoded = max(array_map(fn($f) => $f['end'] - $f['start'], $frames));
            // Canvas, restore-previous snapshot, decoded frame and final sheet, with GD headroom.
            $required = ($w * $h * 2 + $maxFrame + $cw * $ch * $grid * $grid) * 4 + $maxEncoded * 2 + 16777216;
            if ($required > self::availableMemory()) return null;
            self::budget($deadline);
            $canvas = imagecreatetruecolor($w, $h);
            $sheet = imagecreatetruecolor($cw * $grid, $ch * $grid);
            imagefill($sheet, 0, 0, 0xffffff);
            imagefill($canvas, 0, 0, $frames[0]['transparent'] !== null ? 0xffffff : $background);
            $previous = null;
            $cell = 0;
            foreach ($frames as $index => $f) {
                self::budget($deadline);
                if ($f['disposal'] === 3) {
                    $previous = imagecreatetruecolor($w, $h);
                    imagecopy($previous, $canvas, 0, 0, 0, 0, $w, $h);
                }
                $palette = $f['palette'] !== '' ? $f['palette'] : $global;
                $packed = 0x80 | $f['paletteSize'];
                $single = 'GIF89a' . pack('vvCCC', $f['w'], $f['h'], $packed, 0, 0) . $palette;
                if ($f['transparent'] !== null) {
                    $single .= "\x21\xf9\x04\x01\0\0" . chr($f['transparent']) . "\0";
                }
                $single .= ',' . pack('vvvvC', 0, 0, $f['w'], $f['h'], $f['packed'] & 0x40)
                    . substr($bytes, $f['start'], $f['end'] - $f['start']) . ';';
                $frame = @imagecreatefromstring($single);
                if (!$frame) return null;
                self::budget($deadline);
                imagecopy($canvas, $frame, $f['x'], $f['y'], 0, 0, $f['w'], $f['h']);
                unset($frame, $single);
                while (($samples[$cell] ?? null) === $index) {
                    self::budget($deadline);
                    $x = ($cell % $grid) * $cw; $y = intdiv($cell, $grid) * $ch;
                    imagecopyresampled($sheet, $canvas, $x + intdiv($cw - $dw, 2), $y + intdiv($ch - $dh, 2), 0, 0, $dw, $dh, $w, $h);
                    // Number sampled frames, rather than original indices; the prompt states reading order.
                    imagefilledrectangle($sheet, $x, $y, min($x + 13, $x + $cw - 1), min($y + 12, $y + $ch - 1), 0xffffff);
                    imagestring($sheet, 1, $x + 2, $y + 2, (string)($cell + 1), 0x222222);
                    $cell++;
                }
                if ($f['disposal'] === 2) {
                    imagefilledrectangle($canvas, $f['x'], $f['y'], $f['x'] + $f['w'] - 1, $f['y'] + $f['h'] - 1,
                        $f['transparent'] !== null ? 0xffffff : $background);
                } elseif ($f['disposal'] === 3) {
                    $canvas = $previous;
                    $previous = null;
                }
            }
            for ($i = 1; $i < $grid; $i++) {
                imageline($sheet, $i * $cw, 0, $i * $cw, $ch * $grid - 1, 0xaaaaaa);
                imageline($sheet, 0, $i * $ch, $cw * $grid - 1, $i * $ch, 0xaaaaaa);
            }
            self::budget($deadline);
            ob_start();
            try { imagejpeg($sheet, null, VisionFormatter::JPEG_QUALITY); $jpeg = ob_get_contents(); }
            finally { ob_end_clean(); }
            self::budget($deadline);
            return $jpeg !== false && $jpeg !== '' ? ['jpeg' => $jpeg, 'frames' => $cells] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function parse(string $bytes, int $deadline): array {
        $length = strlen($bytes); $pos = 0;
        $read = static function (int $n) use ($bytes, $length, &$pos): string {
            if ($n < 0 || $n > $length - $pos) throw new \RuntimeException('Truncated GIF');
            $part = substr($bytes, $pos, $n); $pos += $n; return $part;
        };
        if (!in_array($read(6), ['GIF87a', 'GIF89a'], true)) throw new \RuntimeException('Invalid GIF signature');
        $screen = unpack('vw/vh/Cpacked/Cbg/Caspect', $read(7));
        $w = $screen['w']; $h = $screen['h'];
        if (!$w || !$h || $w * $h > VisionFormatter::MAX_PIXELS) throw new \RuntimeException('GIF canvas limit');
        $globalSize = $screen['packed'] & 7;
        $global = ($screen['packed'] & 128) ? $read(3 * (2 << $globalSize)) : '';
        $background = 0xffffff;
        if ($global !== '' && $screen['bg'] < strlen($global) / 3) {
            $rgb = unpack('Cr/Cg/Cb', substr($global, $screen['bg'] * 3, 3));
            $background = ($rgb['r'] << 16) | ($rgb['g'] << 8) | $rgb['b'];
        }
        $frames = []; $pixels = 0;
        $control = ['delay' => 100, 'disposal' => 0, 'transparent' => null];
        while ($pos < $length) {
            self::budget($deadline);
            $marker = $read(1);
            if ($marker === ';') return [$w, $h, $global, $background, $frames];
            if ($marker === "\x21") {
                $label = ord($read(1));
                if ($label === 0xf9) {
                    if (ord($read(1)) !== 4) throw new \RuntimeException('Invalid GIF control');
                    $gce = unpack('Cpacked/vdelay/Ctransparent', $read(4));
                    if ($read(1) !== "\0" || ($gce['packed'] & 0xe0) || (($gce['packed'] >> 2) & 7) > 3) throw new \RuntimeException('Invalid GIF disposal');
                    $control = ['delay' => $gce['delay'] <= 1 ? 100 : $gce['delay'] * 10,
                        'disposal' => ($gce['packed'] >> 2) & 7, 'transparent' => ($gce['packed'] & 1) ? $gce['transparent'] : null];
                } else {
                    // Plain text is a rendering block; GD cannot composite it correctly.
                    if ($label === 1) throw new \RuntimeException('Unsupported GIF text frame');
                    self::skipBlocks($read, $deadline);
                }
                continue;
            }
            if ($marker !== ',' || count($frames) >= self::MAX_FRAMES) throw new \RuntimeException('GIF frame limit or invalid block');
            $f = unpack('vx/vy/vw/vh/Cpacked', $read(9));
            if (!$f['w'] || !$f['h'] || $f['x'] + $f['w'] > $w || $f['y'] + $f['h'] > $h || ($f['packed'] & 0x18)) throw new \RuntimeException('Invalid GIF rectangle');
            $pixels += $f['w'] * $f['h'];
            if ($pixels > self::MAX_DECODED_PIXELS) throw new \RuntimeException('GIF pixel budget');
            $f['paletteSize'] = ($f['packed'] & 128) ? $f['packed'] & 7 : $globalSize;
            $f['palette'] = ($f['packed'] & 128) ? $read(3 * (2 << $f['paletteSize'])) : '';
            $palette = $f['palette'] !== '' ? $f['palette'] : $global;
            if ($palette === '' || ($control['transparent'] !== null && $control['transparent'] >= strlen($palette) / 3)) throw new \RuntimeException('Invalid GIF palette');
            $f['start'] = $pos;
            $codeSize = ord($read(1));
            if ($codeSize < 2 || $codeSize > 8) throw new \RuntimeException('Invalid GIF LZW size');
            self::skipBlocks($read, $deadline);
            $f['end'] = $pos;
            $frames[] = $f + $control;
            $control = ['delay' => 100, 'disposal' => 0, 'transparent' => null];
        }
        throw new \RuntimeException('Missing GIF trailer');
    }

    private static function skipBlocks(callable $read, int $deadline): void {
        while (($size = ord($read(1))) !== 0) {
            self::budget($deadline);
            $read($size);
        }
    }

    private static function samples(array $frames): array {
        if (count($frames) <= 9) return array_keys($frames);
        $total = array_sum(array_column($frames, 'delay'));
        $samples = []; $index = 0; $end = $frames[0]['delay'];
        for ($i = 0; $i < 9; $i++) {
            $time = ($total - 1) * $i / 8;
            while ($time >= $end && $index < count($frames) - 1) $end += $frames[++$index]['delay'];
            $samples[] = $index;
        }
        return $samples;
    }

    private static function budget(int $deadline): void {
        if (hrtime(true) >= $deadline) throw new \RuntimeException('GIF time budget');
    }

    private static function availableMemory(): int {
        $limit = trim(ini_get('memory_limit'));
        $bytes = (int)$limit;
        if ($bytes > 0) {
            $bytes *= match (strtolower(substr($limit, -1))) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
            return max(0, min(268435456, $bytes - memory_get_usage(true)));
        }
        return 268435456;
    }
}
