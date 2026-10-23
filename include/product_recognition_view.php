<?php
/**
 * View-page cutout of the recognized product. Not the classifier — that still embeds
 * the whole frame. This decides whether a faces-style panel has a product to draw
 * (studio trim → tight box + transparent PNG mask) and what name to put next to it.
 */

function pr_open_image(string $path)
{
    $info = @getimagesize($path);
    if ($info === false) {
        return null;
    }
    $im = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG => @imagecreatefrompng($path),
        IMAGETYPE_GIF => @imagecreatefromgif($path),
        default => (function_exists('imagecreatefromwebp') && $info[2] === IMAGETYPE_WEBP)
            ? @imagecreatefromwebp($path) : null,
    };
    return ($im === false) ? null : $im;
}

/**
 * Mean corner colour when all four corners agree; null on a full-bleed / lifestyle frame.
 *
 * @return array{0:float,1:float,2:float,3:float}|null  r,g,b,fuzz_px
 */
function pr_studio_bg($im, float $fuzz = 0.12): ?array
{
    $w = imagesx($im);
    $h = imagesy($im);
    $bs = max(2, (int)(min($w, $h) * 0.04));
    $corners = [
        pr_block_mean($im, 0, 0, $bs, $bs),
        pr_block_mean($im, $w - $bs, 0, $w, $bs),
        pr_block_mean($im, 0, $h - $bs, $bs, $h),
        pr_block_mean($im, $w - $bs, $h - $bs, $w, $h),
    ];
    $fuzz_px = $fuzz * 255;
    foreach ($corners as $c) {
        # Lifestyle / location still has similar-ish corners (pavement, sky). A studio
        # sweep is actually light. 3022 (cart, two bags) is 45–82; 57 (ecomm white) is 255.
        if (($c[0] + $c[1] + $c[2]) / 3 < 200) {
            return null;
        }
    }
    for ($i = 0; $i < 4; $i++) {
        for ($j = $i + 1; $j < 4; $j++) {
            if (pr_colour_dist($corners[$i], $corners[$j]) > $fuzz_px * 1.5) {
                return null;
            }
        }
    }
    return [
        ($corners[0][0] + $corners[1][0] + $corners[2][0] + $corners[3][0]) / 4,
        ($corners[0][1] + $corners[1][1] + $corners[2][1] + $corners[3][1]) / 4,
        ($corners[0][2] + $corners[1][2] + $corners[2][2] + $corners[3][2]) / 4,
        $fuzz_px,
    ];
}

function pr_image_max_edge($im, int $max_edge = 400)
{
    $w = imagesx($im);
    $h = imagesy($im);
    if ($w <= $max_edge && $h <= $max_edge) {
        return $im;
    }
    $scale = $max_edge / max($w, $h);
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $small = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($small, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    return $small;
}

/**
 * One tight box (largest component). Kept for the original selfcheck.
 *
 * @return array{0:float,1:float,2:float,3:float}|null
 */
function pr_bbox_from_image(string $path, float $fuzz = 0.12, float $pad = 0.06, float $max_area = 0.90): ?array
{
    $boxes = pr_bboxes_from_image($path, $fuzz, $pad, $max_area);
    return $boxes[0] ?? null;
}

/**
 * Studio trim split into connected components so two bags on white become two rows.
 *
 * @return list<array{0:float,1:float,2:float,3:float}>
 */
function pr_bboxes_from_image(string $path, float $fuzz = 0.12, float $pad = 0.06, float $max_area = 0.90): array
{
    $im = pr_open_image($path);
    if ($im === null) {
        return [];
    }

    $im = pr_image_max_edge($im);
    $w = imagesx($im);
    $h = imagesy($im);

    $bg = pr_studio_bg($im, $fuzz);
    if ($bg === null) {
        return [];
    }

    $fg = array_fill(0, $w * $h, false);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgb = imagecolorat($im, $x, $y);
            $c = [($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255];
            if (pr_colour_dist($c, $bg) > $bg[3]) {
                $fg[$y * $w + $x] = true;
            }
        }
    }

    $min_pixels = (int)max(16, $w * $h * 0.015);
    $boxes = [];
    $seen = array_fill(0, $w * $h, false);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $i = $y * $w + $x;
            if (!$fg[$i] || $seen[$i]) {
                continue;
            }
            $stack = [$i];
            $seen[$i] = true;
            $minx = $maxx = $x;
            $miny = $maxy = $y;
            $count = 0;
            while ($stack !== []) {
                $p = array_pop($stack);
                $px = $p % $w;
                $py = intdiv($p, $w);
                $count++;
                if ($px < $minx) {
                    $minx = $px;
                }
                if ($px > $maxx) {
                    $maxx = $px;
                }
                if ($py < $miny) {
                    $miny = $py;
                }
                if ($py > $maxy) {
                    $maxy = $py;
                }
                foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                    $nx = $px + $dx;
                    $ny = $py + $dy;
                    if ($nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h) {
                        continue;
                    }
                    $ni = $ny * $w + $nx;
                    if (!$fg[$ni] || $seen[$ni]) {
                        continue;
                    }
                    $seen[$ni] = true;
                    $stack[] = $ni;
                }
            }
            if ($count < $min_pixels) {
                continue;
            }
            $x1 = max(0.0, $minx / $w - $pad);
            $y1 = max(0.0, $miny / $h - $pad);
            $x2 = min(1.0, ($maxx + 1) / $w + $pad);
            $y2 = min(1.0, ($maxy + 1) / $h + $pad);
            if (($x2 - $x1) * ($y2 - $y1) >= $max_area) {
                continue;
            }
            $boxes[] = [round($x1, 4), round($y1, 4), round($x2, 4), round($y2, 4)];
        }
    }
    usort($boxes, function ($a, $b) {
        return (($b[2] - $b[0]) * ($b[3] - $b[1])) <=> (($a[2] - $a[0]) * ($a[3] - $a[1]));
    });
    return pr_coalesce_boxes($boxes);
}

/**
 * Area of a 0–1 box. Zero for inverted / empty.
 *
 * @param array{0:float,1:float,2:float,3:float} $b
 */
function pr_box_area(array $b): float
{
    return max(0.0, ($b[2] - $b[0]) * ($b[3] - $b[1]));
}

/**
 * @param array{0:float,1:float,2:float,3:float} $a
 * @param array{0:float,1:float,2:float,3:float} $b
 */
function pr_box_intersect_area(array $a, array $b): float
{
    $x1 = max($a[0], $b[0]);
    $y1 = max($a[1], $b[1]);
    $x2 = min($a[2], $b[2]);
    $y2 = min($a[3], $b[3]);
    return pr_box_area([$x1, $y1, $x2, $y2]);
}

/**
 * One ecomm product is often two /segment fragments whose boxes overlap heavily.
 * Adjacent products must not merge: a 4% pad-touch rule collapsed two golf bags
 * into one full-frame box (35062).
 *
 * @param array{0:float,1:float,2:float,3:float} $a
 * @param array{0:float,1:float,2:float,3:float} $b
 */
function pr_boxes_should_merge(array $a, array $b): bool
{
    $inter = pr_box_intersect_area($a, $b);
    $aa = pr_box_area($a);
    $ab = pr_box_area($b);
    $smaller = min($aa, $ab);
    if ($smaller > 0 && $inter / $smaller >= 0.35) {
        return true;
    }
    $union = $aa + $ab - $inter;
    return $union > 0 && $inter / $union >= 0.15;
}

/**
 * @param list<array{0:float,1:float,2:float,3:float}> $boxes
 * @return list<array{0:float,1:float,2:float,3:float}>
 */
function pr_coalesce_boxes(array $boxes): array
{
    $boxes = array_values($boxes);
    $changed = true;
    while ($changed) {
        $changed = false;
        $n = count($boxes);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if (!pr_boxes_should_merge($boxes[$i], $boxes[$j])) {
                    continue;
                }
                $boxes[$i] = [
                    round(min($boxes[$i][0], $boxes[$j][0]), 4),
                    round(min($boxes[$i][1], $boxes[$j][1]), 4),
                    round(max($boxes[$i][2], $boxes[$j][2]), 4),
                    round(max($boxes[$i][3], $boxes[$j][3]), 4),
                ];
                array_splice($boxes, $j, 1);
                $changed = true;
                break 2;
            }
        }
    }
    usort($boxes, function ($a, $b) {
        return pr_box_area($b) <=> pr_box_area($a);
    });
    return pr_expand_boxes($boxes);
}

/**
 * /segment and studio CCs often clip straps and club tops. Pad after merge.
 *
 * @param list<array{0:float,1:float,2:float,3:float}> $boxes
 * @return list<array{0:float,1:float,2:float,3:float}>
 */
function pr_expand_boxes(array $boxes, float $pad = 0.08): array
{
    $out = [];
    foreach ($boxes as $b) {
        $out[] = [
            round(max(0.0, $b[0] - $pad), 4),
            round(max(0.0, $b[1] - $pad), 4),
            round(min(1.0, $b[2] + $pad), 4),
            round(min(1.0, $b[3] + $pad), 4),
        ];
    }
    return $out;
}

/**
 * Crop of $src inside $bbox, background pixels made transparent.
 *
 * @param array{0:float,1:float,2:float,3:float} $bbox
 */
function pr_write_product_mask(string $src, array $bbox, string $dest, float $fuzz = 0.12): bool
{
    $im = pr_open_image($src);
    if ($im === null) {
        return false;
    }
    $bg = pr_studio_bg(pr_image_max_edge($im), $fuzz);
    if ($bg === null) {
        return pr_write_bbox_crop($src, $bbox, $dest);
    }

    $w = imagesx($im);
    $h = imagesy($im);
    $x1 = max(0, (int)floor($bbox[0] * $w));
    $y1 = max(0, (int)floor($bbox[1] * $h));
    $x2 = min($w, (int)ceil($bbox[2] * $w));
    $y2 = min($h, (int)ceil($bbox[3] * $h));
    $cw = $x2 - $x1;
    $ch = $y2 - $y1;
    if ($cw < 8 || $ch < 8) {
        return false;
    }

    $out = imagecreatetruecolor($cw, $ch);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    $clear = imagecolorallocatealpha($out, 0, 0, 0, 127);
    imagefill($out, 0, 0, $clear);

    for ($y = $y1; $y < $y2; $y++) {
        for ($x = $x1; $x < $x2; $x++) {
            $rgb = imagecolorat($im, $x, $y);
            $c = [($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255];
            if (pr_colour_dist($c, $bg) <= $bg[3]) {
                continue;
            }
            imagesetpixel(
                $out,
                $x - $x1,
                $y - $y1,
                imagecolorallocatealpha($out, (int)$c[0], (int)$c[1], (int)$c[2], 0)
            );
        }
    }

    $dir = dirname($dest);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        return false;
    }
    $tmp = $dest . '.tmp';
    if (!imagepng($out, $tmp) || !rename($tmp, $dest)) {
        pr_unlink($tmp);
        return false;
    }
    pr_unlink($dest . '.fail');
    return true;
}

/**
 * Opaque crop when the frame has no studio background to punch out.
 *
 * @param array{0:float,1:float,2:float,3:float} $bbox
 */
function pr_write_bbox_crop(string $src, array $bbox, string $dest): bool
{
    $im = pr_open_image($src);
    if ($im === null) {
        return false;
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $x1 = max(0, (int)floor($bbox[0] * $w));
    $y1 = max(0, (int)floor($bbox[1] * $h));
    $x2 = min($w, (int)ceil($bbox[2] * $w));
    $y2 = min($h, (int)ceil($bbox[3] * $h));
    $cw = $x2 - $x1;
    $ch = $y2 - $y1;
    if ($cw < 8 || $ch < 8) {
        return false;
    }
    $out = imagecreatetruecolor($cw, $ch);
    imagecopy($out, $im, 0, 0, $x1, $y1, $cw, $ch);
    $dir = dirname($dest);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        return false;
    }
    $tmp = $dest . '.tmp';
    if (!imagepng($out, $tmp) || !rename($tmp, $dest)) {
        pr_unlink($tmp);
        return false;
    }
    pr_unlink($dest . '.fail');
    return true;
}

/** @param array{0:float,1:float,2:float} $a
 *  @param array{0:float,1:float,2:float} $b */
function pr_colour_dist(array $a, array $b): float
{
    return max(abs($a[0] - $b[0]), abs($a[1] - $b[1]), abs($a[2] - $b[2]));
}

/** @return array{0:float,1:float,2:float} */
function pr_block_mean($im, int $x0, int $y0, int $x1, int $y1): array
{
    $r = 0;
    $g = 0;
    $b = 0;
    $n = 0;
    for ($y = $y0; $y < $y1; $y++) {
        for ($x = $x0; $x < $x1; $x++) {
            $rgb = imagecolorat($im, $x, $y);
            $r += ($rgb >> 16) & 255;
            $g += ($rgb >> 8) & 255;
            $b += $rgb & 255;
            $n++;
        }
    }
    if ($n === 0) {
        return [0.0, 0.0, 0.0];
    }
    return [$r / $n, $g / $n, $b / $n];
}

function pr_mask_dir(): string
{
    global $storagedir;
    # tmp/, not filestore root: derived masks should not land on an original-file mount.
    return $storagedir . '/tmp/product_recognition';
}

function pr_unlink(string $path): void
{
    if (is_file($path)) {
        unlink($path);
    }
}

function pr_mask_file(int $ref, string $checksum, int $index = 0): string
{
    $safe = preg_replace('/[^a-fA-F0-9]/', '', $checksum);
    if ($safe === '') {
        $safe = 'none';
    }
    return pr_mask_dir() . '/' . $ref . '_' . $safe . '_' . $index . '.png';
}

function pr_mask_is_ready(int $ref, string $checksum, int $index): bool
{
    $mask = pr_mask_file($ref, $checksum, $index);
    return file_exists($mask) || file_exists($mask . '.fail');
}

function pr_mark_mask_failed(string $mask): void
{
    @file_put_contents($mask . '.fail', '');
}

function pr_purge_masks(int $ref): void
{
    $files = glob(pr_mask_dir() . '/' . $ref . '_*') ?: [];
    foreach ($files as $file) {
        unlink($file);
    }
}

function pr_purge_stale_masks(int $ref, string $checksum): void
{
    $safe = preg_replace('/[^a-fA-F0-9]/', '', $checksum);
    if ($safe === '') {
        $safe = 'none';
    }
    $keep = $ref . '_' . $safe . '_';
    foreach (glob(pr_mask_dir() . '/' . $ref . '_*') ?: [] as $file) {
        if (!str_starts_with(basename($file), $keep)) {
            unlink($file);
        }
    }
}

function pr_preview_path(int $ref): ?string
{
    foreach (['pre', 'scr'] as $size) {
        $path = get_resource_path($ref, true, $size, false, 'jpg');
        if (file_exists($path)) {
            return $path;
        }
    }
    return null;
}

function pr_write_mask_bytes(string $dest, string $bytes): bool
{
    if ($bytes === '') {
        return false;
    }
    $dir = dirname($dest);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        return false;
    }
    $tmp = $dest . '.tmp';
    if (file_put_contents($tmp, $bytes) === false || !rename($tmp, $dest)) {
        pr_unlink($tmp);
        return false;
    }
    pr_unlink($dest . '.fail');
    return true;
}

/**
 * Lifestyle instances from POST /segment. Null means the service did not answer
 * (do not cache a negative). Empty boxes means it looked and found nothing.
 *
 * @return array{boxes:list<array{0:float,1:float,2:float,3:float}>,pngs:list<string>}|null
 */
function pr_segment_products(string $file): ?array
{
    # First /segment loads YOLO-World + SAM2; 180s covers that. Connect is short so a
    # down service does not stall the resource view page.
    # Always send `classes` so FastAPI File+Form does not 422 when the field is omitted.
    $fields = [
        "classes" => trim((string)($GLOBALS["product_recognition_segment_classes"] ?? "")),
    ];
    $response = pr_service_post("/segment", $fields, $file, 180, 2);
    if ($response === null) {
        return null;
    }
    if (!isset($response['regions']) || !is_array($response['regions'])) {
        return ['boxes' => [], 'pngs' => []];
    }
    $boxes = [];
    $pngs = [];
    foreach ($response['regions'] as $region) {
        if (!isset($region['bbox']) || !is_array($region['bbox']) || count($region['bbox']) !== 4) {
            continue;
        }
        $boxes[] = [
            round((float)$region['bbox'][0], 4),
            round((float)$region['bbox'][1], 4),
            round((float)$region['bbox'][2], 4),
            round((float)$region['bbox'][3], 4),
        ];
        $png = '';
        if (!empty($region['png_base64'])) {
            $decoded = base64_decode((string)$region['png_base64'], true);
            if ($decoded !== false) {
                $png = $decoded;
            }
        }
        $pngs[] = $png;
    }
    return ['boxes' => $boxes, 'pngs' => $pngs];
}

function pr_region_stamp(string $checksum): string
{
    # cw4: merge only on real overlap (no pad-touch); adjacent bags stay separate.
    return $checksum . ':cw4';
}

function pr_region_is_current_file(string $stored, string $checksum): bool
{
    return $stored === $checksum || str_starts_with($stored, $checksum . ':');
}

/**
 * Stored lifestyle/studio region count for the current file stamp. Zero means no row yet
 * (typical packshot) — not "multi-product".
 */
function pr_stored_region_count(int $ref): int
{
    $resource = get_resource_data($ref);
    if ($resource === false) {
        return 0;
    }
    $checksum = (string)$resource['file_checksum'];
    $row = ps_query('SELECT bbox, checksum FROM product_region WHERE resource = ?', ['i', $ref]);
    if ($row === [] || (string)$row[0]['checksum'] !== pr_region_stamp($checksum)) {
        return 0;
    }
    return count(pr_decode_bboxes((string)$row[0]['bbox']));
}

function pr_cached_regions(int $ref): array
{
    $resource = get_resource_data($ref);
    if ($resource === false) {
        return [];
    }
    $checksum = (string)$resource['file_checksum'];
    $row = ps_query('SELECT bbox, checksum FROM product_region WHERE resource = ?', ['i', $ref]);
    if ($row === [] || (string)$row[0]['checksum'] !== pr_region_stamp($checksum)) {
        return [];
    }
    $boxes = pr_decode_bboxes($row[0]['bbox']);
    foreach ($boxes as $i => $_bbox) {
        if (!pr_mask_is_ready($ref, $checksum, $i)) {
            return [];
        }
    }
    return $boxes;
}

/**
 * @param list<array{0:float,1:float,2:float,3:float}> $boxes
 * @param list<string> $pngs
 */
function pr_commit_regions(int $ref, string $checksum, array $boxes, array $pngs, ?string $path): void
{
    $stored = $boxes === [] ? '' : json_encode($boxes);
    ps_query(
        'INSERT INTO product_region (resource, bbox, checksum) VALUES (?,?,?) AS new
         ON DUPLICATE KEY UPDATE bbox = new.bbox, checksum = new.checksum',
        ['i', $ref, 's', $stored, 's', pr_region_stamp($checksum)]
    );
    pr_purge_stale_masks($ref, $checksum);
    $safe = preg_replace('/[^a-fA-F0-9]/', '', $checksum);
    if ($safe === '') {
        $safe = 'none';
    }
    foreach (glob(pr_mask_dir() . '/' . $ref . '_' . $safe . '_*.identify.json') ?: [] as $file) {
        unlink($file);
    }
    foreach ($boxes as $i => $bbox) {
        $mask = pr_mask_file($ref, $checksum, $i);
        $ok = false;
        if (isset($pngs[$i]) && $pngs[$i] !== '') {
            $ok = pr_write_mask_bytes($mask, $pngs[$i]);
        } elseif ($path !== null) {
            $ok = pr_write_product_mask($path, $bbox, $mask);
        }
        if (!$ok) {
            logScript("product_recognition: mask write failed for resource $ref region $i");
            pr_mark_mask_failed($mask);
        }
    }
}

/**
 * Cached studio trim + lifestyle cutouts. Recomputes when file_checksum changes.
 * Empty stored bbox means we already looked (studio, and /segment if it answered)
 * and there is no product instance.
 *
 * @return list<array{0:float,1:float,2:float,3:float}>
 */
function pr_ensure_regions(int $ref): array
{
    $resource = get_resource_data($ref);
    if ($resource === false) {
        return [];
    }
    $checksum = (string)$resource['file_checksum'];
    $row = ps_query('SELECT bbox, checksum FROM product_region WHERE resource = ?', ['i', $ref]);
    if ($row !== [] && (string)$row[0]['checksum'] === pr_region_stamp($checksum)) {
        $boxes = pr_decode_bboxes($row[0]['bbox']);
        if ($boxes === []) {
            return [];
        }
        $ready = true;
        foreach ($boxes as $i => $_bbox) {
            if (!pr_mask_is_ready($ref, $checksum, $i)) {
                $ready = false;
                break;
            }
        }
        if ($ready) {
            return $boxes;
        }
    }

    $path = pr_preview_path($ref);
    $boxes = $path !== null ? pr_bboxes_from_image($path) : [];
    $pngs = [];
    if ($boxes === [] && $path !== null) {
        $seg = pr_segment_products($path);
        if ($seg === null) {
            # Service down: do not cache a negative so the next compute can retry.
            return [];
        }
        # /segment often splits one bag into overlapping parts; coalesce before store.
        # Drop service PNGs — indices no longer match after merge; rewrite from preview.
        $boxes = pr_coalesce_boxes($seg['boxes']);
        $pngs = [];
    }
    pr_commit_regions($ref, $checksum, $boxes, $pngs, $path);
    return $boxes;
}

function pr_ensure_region(int $ref): ?array
{
    return pr_ensure_regions($ref)[0] ?? null;
}

/** @return list<array{0:float,1:float,2:float,3:float}> */
function pr_decode_bboxes(?string $stored): array
{
    if ($stored === null || $stored === '') {
        return [];
    }
    if ($stored[0] === '[') {
        $json = json_decode($stored, true);
        if (!is_array($json) || $json === []) {
            return [];
        }
        if (isset($json[0]) && is_array($json[0])) {
            return $json;
        }
        if (count($json) === 4) {
            return [$json];
        }
        return [];
    }
    $parts = array_map('floatval', explode(',', $stored));
    if (count($parts) !== 4) {
        return [];
    }
    return [$parts];
}

/** @return array{0:float,1:float,2:float,3:float}|null */
function pr_decode_bbox(?string $stored): ?array
{
    return pr_decode_bboxes($stored)[0] ?? null;
}

function pr_product_label(string $filename): string
{
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $base = preg_replace('/_a\d+_HR$/i', '', $base) ?? $base;
    $base = preg_replace('/_HR$/i', '', $base) ?? $base;
    $base = preg_replace('/^s\d+_/i', '', $base) ?? $base;
    return trim(str_replace('_', ' ', $base));
}

function pr_field_leaf_names(int $resource, int $field): array
{
    if ($field <= 0) {
        return [];
    }
    $rows = ps_query(
        "SELECT n.name
           FROM resource_node rn
           JOIN node n ON n.ref = rn.node AND n.resource_type_field = ?
          WHERE rn.resource = ?
            AND NOT EXISTS (SELECT 1 FROM node ch WHERE ch.parent = n.ref)
          ORDER BY n.name",
        ['i', $field, 'i', $resource]
    );
    $out = [];
    foreach ($rows as $row) {
        $out[] = (string)$row['name'];
    }
    return $out;
}

/**
 * Distinct colorway leaves on reference photos of this model.
 *
 * @return list<string>
 */
function pr_reference_colorways(int $model_node): array
{
    global $product_recognition_colorway_field;

    $field = (int)($product_recognition_colorway_field ?? 0);
    if ($model_node <= 0 || $field <= 0) {
        return [];
    }
    $rows = ps_query(
        "SELECT DISTINCT n.name
           FROM product_reference r
           JOIN resource_node rn ON rn.resource = r.resource
           JOIN node n ON n.ref = rn.node AND n.resource_type_field = ?
          WHERE r.model_node = ?
            AND r.status IN ('derived','pinned')
            AND NOT EXISTS (SELECT 1 FROM node ch WHERE ch.parent = n.ref)
          ORDER BY n.name",
        ['i', $field, 'i', $model_node]
    );
    $out = [];
    foreach ($rows as $row) {
        $out[] = (string)$row['name'];
    }
    return $out;
}

function pr_identity_colorway(int $model_node, int $top_reference): string
{
    unset($top_reference);
    $name = pr_leaf_models()[$model_node] ?? '';
    $want = pr_fold_label($name);
    $catalog_cws = [];
    if ($want !== '') {
        foreach (pr_catalog_picker_rows() as $row) {
            if (pr_fold_label($row['model_text']) === $want && $row['colorway_text'] !== '') {
                $catalog_cws[] = $row['colorway_text'];
            }
        }
    }
    return pr_infer_colorway($catalog_cws, pr_reference_colorways($model_node));
}

/**
 * @param list<string> $prefer_models  If the resource is already tagged, use that model's
 *   candidate rather than a rival that won the vote. Colorway is voted from that
 *   Name's neighbors. A blank vote still falls through to the one-colour catalog.
 * @return array{model_node:int,model_name:string,colorway:string,confidence:?float,top_reference:?int}
 */
function pr_identity_from_identify_result(?array $result, array $leaf_models = [],
                                          array $prefer_models = []): array
{
    $empty = [
        'model_node' => 0,
        'model_name' => '',
        'colorway' => '',
        'confidence' => null,
        'top_reference' => null,
    ];
    if ($result === null || empty($result['candidates']) || !is_array($result['candidates'][0])) {
        return $empty;
    }
    $top = $result['candidates'][0];
    if ($prefer_models !== []) {
        $matched = null;
        foreach ($result['candidates'] as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $node = (int)($candidate['model_node'] ?? 0);
            $name = ($node > 0 && isset($leaf_models[$node])) ? (string)$leaf_models[$node] : '';
            if ($name !== '' && in_array($name, $prefer_models, true)) {
                $matched = $candidate;
                break;
            }
        }
        if ($matched === null) {
            return $empty;
        }
        $top = $matched;
    }
    if ($prefer_models === [] && !empty($result['neighbors']) && is_array($result['neighbors'])) {
        $agg = pr_aggregate_service_neighbors($result['neighbors'], $leaf_models);
        if ($agg['suggest']) {
            $label = $agg['name'] !== '' ? $agg['name'] : $agg['family'];
            $node = 0;
            foreach ($leaf_models as $id => $leaf) {
                if (pr_fold_label((string)$leaf) === pr_fold_label($label)) {
                    $node = (int)$id;
                    break;
                }
            }
            return [
                'model_node' => $node,
                'model_name' => $label,
                'colorway' => $agg['name'] !== '' ? $agg['colorway'] : '',
                'confidence' => $agg['confidence'],
                'top_reference' => $agg['name'] !== '' && $agg['top_reference'] > 0 ? $agg['top_reference'] : null,
                'family' => $agg['family'],
                'subclass' => $agg['subclass'],
                'subclass2' => $agg['subclass2'],
                'name_resolved' => $agg['name'] !== '',
            ];
        }
    }
    $node = (int)($top['model_node'] ?? 0);
    $ref = (int)($top['top_reference'] ?? $result['top_reference'] ?? 0);
    $model_name = ($node > 0 && isset($leaf_models[$node])) ? (string)$leaf_models[$node] : '';
    $facets = ['family' => '', 'subclass' => '', 'subclass2' => ''];
    $identity = $model_name !== '' ? pr_identity_for_reference_label($model_name) : null;
    if ($identity !== null) {
        $facets = pr_facets_from_identity($identity);
    }
    $colorway = '';
    if ($prefer_models !== [] && $model_name !== ''
        && !empty($result['neighbors']) && is_array($result['neighbors'])) {
        $colorway = pr_voted_colorway_for_names($result['neighbors'], $leaf_models, [$model_name]);
    }
    return [
        'model_node' => $node,
        'model_name' => $model_name,
        'colorway' => $colorway,
        'confidence' => isset($top['confidence']) ? (float)$top['confidence'] : null,
        'top_reference' => $ref > 0 ? $ref : null,
        'family' => $facets['family'],
        'subclass' => $facets['subclass'],
        'subclass2' => $facets['subclass2'],
        'name_resolved' => $identity !== null,
    ];
}

function pr_identify_cache_file(int $ref, string $checksum, int $index): string
{
    return preg_replace('/\.png$/', '.identify.json', pr_mask_file($ref, $checksum, $index)) ?? '';
}

/**
 * DINOv2 /identify on one cutout. A blank colorway vote is filled only when
 * that model has one colour in the catalog (or, with no sheet row, on its reference photos).
 *
 * @return array{model_node:int,model_name:string,colorway:string,confidence:?float,top_reference:?int}
 */
function pr_region_identity(int $ref, string $checksum, int $index, string $mask,
                            array $leaf_models = [], array $prefer_models = [],
                            bool $may_fetch = true): array
{
    global $product_recognition_space, $product_recognition_k, $mysql_db;

    $cache = pr_identify_cache_file($ref, $checksum, $index);
    if ($cache !== '' && is_file($cache)) {
        $cached = json_decode((string)file_get_contents($cache), true);
        if (is_array($cached) && array_key_exists('colorway', $cached)
            && array_key_exists('model_name', $cached)
            && array_key_exists('model_node', $cached)
            && ($cached['prefer_models'] ?? null) === $prefer_models) {
            unset($cached['prefer_models']);
            return $cached + pr_identity_from_identify_result(null);
        }
    }

    $empty = pr_identity_from_identify_result(null);
    if (!$may_fetch || !is_file($mask)) {
        return $empty;
    }

    # First call may load DINOv2. Connect stays short so a down service does not stall view.php.
    $result = pr_service_post('/identify', [
        'db' => (string)$mysql_db,
        'space' => (string)$product_recognition_space,
        'k' => (int)$product_recognition_k,
        'top_n' => 10,
    ], $mask, 90, 2);

    if ($leaf_models === []) {
        $leaf_models = pr_leaf_models();
    }
    $identity = pr_identity_from_identify_result($result, $leaf_models, $prefer_models);
    if ($identity['model_node'] > 0 && ($identity['name_resolved'] ?? true) && $identity['colorway'] === '') {
        $identity['colorway'] = pr_identity_colorway(
            $identity['model_node'],
            (int)($identity['top_reference'] ?? 0)
        );
    }

    if ($result !== null && $cache !== '') {
        $dir = dirname($cache);
        if (is_dir($dir) || mkdir($dir, 0777, true) || is_dir($dir)) {
            file_put_contents($cache, json_encode($identity + ['prefer_models' => $prefer_models]));
        }
    }
    return $identity;
}

/**
 * One panel row per detected region. Lifestyle frames with no studio trim and no
 * cached /segment still get one picker row (preview thumbnail) so an operator can
 * set Model / Colorway — view must not POST /segment (180s).
 *
 * @return list<array{region:int,suggestion:int,product:string,model_name:string,model_node:int,colorway:string,confidence:?float,fallback:bool}>
 */
function pr_view_product_rows(int $resource): array
{
    global $product_recognition_colorway_field;
    $product_recognition_field = pr_product_field();

    if (!pr_is_configured()) {
        return [];
    }

    $data = get_resource_data($resource);
    if ($data === false) {
        return [];
    }
    $checksum = (string)$data['file_checksum'];

    # Cache only. Studio trim stays on the preview hook and the CLI. Statting
    # or decoding the preview here waits on a cold Backblaze file.
    $boxes = pr_cached_regions($resource);
    $fallback = false;
    if ($boxes === []) {
        if ((int) ($data['has_image'] ?? 0) === 0) {
            return [];
        }
        # Not written to product_region — pickers only until segment/studio succeeds.
        $boxes = [[0.0, 0.0, 1.0, 1.0]];
        $fallback = true;
    }

    $filename_field = (int)($GLOBALS["filename_field"] ?? 51);
    $filename = ps_value(
        "SELECT n.name AS value FROM resource_node rn
           JOIN node n ON n.ref = rn.node AND n.resource_type_field = ?
          WHERE rn.resource = ? LIMIT 1",
        ['i', $filename_field, 'i', $resource],
        ''
    );
    if ($filename === '') {
        $filename = (string)($data['field8'] ?? '');
    }
    $product = pr_product_label($filename);
    $colorway_field = (int)($product_recognition_colorway_field ?? 0);
    $colorway = $colorway_field > 0
        ? implode(', ', pr_field_leaf_names($resource, $colorway_field))
        : '';

    $open = ps_query(
        "SELECT s.ref, s.confidence, n.name AS model_name
           FROM product_suggestion s
           JOIN node n ON n.ref = s.model_node
          WHERE s.resource = ? AND s.state = 'open'
            AND NOT EXISTS (
                  SELECT 1 FROM resource_node rn
                    JOIN node mn ON mn.ref = rn.node AND mn.resource_type_field = ?
                   WHERE rn.resource = s.resource)
          ORDER BY s.rank_order ASC, s.confidence DESC",
        ['i', $resource, 'i', $product_recognition_field]
    );

    $catalog = pr_catalog_picker_rows();
    $model_leaves = pr_field_leaf_names($resource, (int)$product_recognition_field);
    $model = pr_primary_tagged_model($model_leaves, $catalog);
    $suggestion = 0;
    $confidence = null;
    if ($open !== []) {
        $model = (string)$open[0]['model_name'];
        $suggestion = (int)$open[0]['ref'];
        $confidence = (float)$open[0]['confidence'];
    }

    $leaves = pr_leaf_models();
    # Multi-instance frames (3022: two bags) must identify each crop on its own.
    # A resource-level tag must not force every row onto the same model.
    $prefer = ($fallback || count($boxes) > 1 || $model_leaves === [])
        ? []
        : $model_leaves;
    $out = [];
    foreach ($boxes as $i => $_bbox) {
        if ($fallback) {
            $out[] = [
                'region' => 0,
                'suggestion' => $suggestion,
                'product' => $product,
                'model_name' => $model,
                'model_node' => 0,
                'colorway' => $colorway,
                'confidence' => $confidence,
                'fallback' => true,
            ];
            break;
        }
        $mask = pr_mask_file($resource, $checksum, $i);
        $identity = pr_region_identity($resource, $checksum, $i, $mask, $leaves, $prefer, false);
        $row_model = $identity['model_name'] !== '' ? $identity['model_name'] : $model;
        # One Colorway on the resource applies only when there is one product. Two bags
        # (black+yellow vs coral) must not share one resource-level colour.
        $row_cw = '';
        if (count($boxes) === 1 && $colorway !== '') {
            $row_cw = $colorway;
        } elseif ($identity['colorway'] !== '') {
            $row_cw = $identity['colorway'];
        }
        if ($row_cw === '' && $row_model !== '' && ($identity['name_resolved'] ?? true) && is_file($mask)) {
            $row_cw = pr_colorway_from_mask($mask, $row_model, $catalog);
        }
        $out[] = [
            'region' => $i,
            'suggestion' => $suggestion,
            'product' => $product,
            'model_name' => $row_model,
            'model_node' => (int)$identity['model_node'],
            'family' => (string)($identity['family'] ?? ''),
            'subclass' => (string)($identity['subclass'] ?? ''),
            'subclass2' => (string)($identity['subclass2'] ?? ''),
            'colorway' => $row_cw,
            # Tagged resources already have a model; the vote share is not a suggestion score.
            'confidence' => $prefer === [] ? ($identity['confidence'] ?? $confidence) : $confidence,
            'fallback' => false,
        ];
    }
    return $out;
}
