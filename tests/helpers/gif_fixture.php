<?php
/** Small synthetic GIF encoder: fixed palette, independent LZW clear codes, no external tools. */
function gif_fixture(array $frames, int $width = 48, int $height = 48): string {
    $palette = [[255,255,255],[240,20,20],[20,220,20],[20,20,240],[240,220,20],[20,220,220],
        [220,20,220],[120,30,20],[20,120,30],[30,20,120],[240,120,20],[120,240,20],
        [20,120,240],[120,20,240],[20,20,20],[140,140,140]];
    $globalPalette = '';
    foreach ($palette as $rgb) $globalPalette .= pack('CCC', ...$rgb);
    $gif = 'GIF89a' . pack('vvCCC', $width, $height, 0xf3, 0, 0) . $globalPalette;
    foreach ($frames as $f) {
        $w = $f['w'] ?? $width; $h = $f['h'] ?? $height;
        $transparent = $f['transparent'] ?? null;
        $gif .= "\x21\xf9\x04" . chr((($f['disposal'] ?? 1) << 2) | ($transparent !== null ? 1 : 0))
            . pack('v', $f['delay'] ?? 10) . chr($transparent ?? 0) . "\0";
        $gif .= ',' . pack('vvvvC', $f['x'] ?? 0, $f['y'] ?? 0, $w, $h, (($f['interlace'] ?? false) ? 0x40 : 0) | (isset($f['palette']) ? 0x83 : 0));
        if (isset($f['palette'])) {
            foreach ($f['palette'] as $rgb) $gif .= pack('CCC', ...$rgb);
        }
        $data = ''; $bits = 0; $count = 0;
        $write = function (int $code) use (&$data, &$bits, &$count): void {
            $bits |= $code << $count; $count += 5;
            while ($count >= 8) { $data .= chr($bits & 255); $bits >>= 8; $count -= 8; }
        };
        // Interlaced raster order: rows 0/8..., 4/8..., 2/4..., 1/2....
        $rows = range(0, $h - 1);
        if ($f['interlace'] ?? false) {
            $rows = [];
            foreach ([[0,8],[4,8],[2,4],[1,2]] as [$start,$step]) {
                for ($row=$start;$row<$h;$row+=$step) $rows[]=$row;
            }
        }
        foreach ($rows as $row) for ($col=0;$col<$w;$col++) {
            $i=$row*$w+$col;
            $write(16); $write($f['pixels'][$i] ?? $f['color'] ?? 0);
        }
        $write(17); if ($count) $data .= chr($bits & 255);
        $gif .= "\x04";
        foreach (str_split($data, 255) as $part) $gif .= chr(strlen($part)) . $part;
        $gif .= "\0";
    }
    return $gif . ';';
}
