<?php

declare(strict_types=1);

namespace App\Services\Update;

use RuntimeException;
use ZipArchive;

/**
 * Defensive ZIP extraction: rejects absolute paths, drive letters, "..", backslashes, NUL bytes,
 * symlinks, too many files, oversize / high-ratio (zip bomb) entries, and anything outside an allow-list.
 * Every written path is re-checked to resolve inside the destination directory.
 */
final class SafeZip
{
    public function __construct(
        private int $maxFiles = 20000,
        private int $maxTotalBytes = 300 * 1024 * 1024,
        private int $maxRatio = 200,
    ) {
    }

    public static function cleanPath(string $name): ?string
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\')) {
            return null;
        }
        if ($name[0] === '/' || preg_match('#^[A-Za-z]:#', $name)) {
            return null;
        }
        $parts = [];
        foreach (explode('/', $name) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                return null;
            }
            if (preg_match('/[\x00-\x1F]/', $seg)) {
                return null;
            }
            $parts[] = $seg;
        }
        return $parts ? implode('/', $parts) : null;
    }

    /**
     * @param callable(string):bool $allow  relative path allow-list check
     * @return list<string> relative paths of extracted files
     */
    public function extract(string $zipFile, string $dest, callable $allow): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Package is not a valid ZIP archive');
        }
        if ($zip->numFiles > $this->maxFiles) {
            throw new RuntimeException('Package has too many files');
        }
        if (!is_dir($dest) && !mkdir($dest, 0755, true)) {
            throw new RuntimeException('Cannot create staging directory');
        }
        $destReal = realpath($dest);
        if ($destReal === false) {
            throw new RuntimeException('Staging directory missing');
        }

        // Pass 1: validate every entry and find a single top-level folder to strip (e.g. "nextaleadmap/").
        $entries = [];
        $total = 0;
        $tops = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            if ($st === false) {
                throw new RuntimeException('Corrupt ZIP entry');
            }
            $raw = (string) $st['name'];
            $isDir = str_ends_with($raw, '/');
            $clean = self::cleanPath($raw);
            if ($clean === null) {
                throw new RuntimeException('Unsafe path in package: ' . substr($raw, 0, 120));
            }
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX) {
                $type = ($attr >> 16) & 0170000;
                if ($type === 0120000) {
                    throw new RuntimeException('Symbolic links are not allowed in packages: ' . $clean);
                }
            }
            if ($isDir) {
                continue;
            }
            $size = (int) $st['size'];
            $comp = max(1, (int) $st['comp_size']);
            $total += $size;
            if ($total > $this->maxTotalBytes) {
                throw new RuntimeException('Package expands beyond the size limit');
            }
            if ($size > 1024 * 1024 && $size / $comp > $this->maxRatio) {
                throw new RuntimeException('Suspicious compression ratio in ' . $clean);
            }
            $entries[] = [$i, $clean, $size];
            $tops[explode('/', $clean)[0]] = true;
        }
        $strip = '';
        if (count($tops) === 1) {
            $top = (string) array_key_first($tops);
            $hasNested = false;
            foreach ($entries as [, $p]) {
                if (str_contains($p, '/')) {
                    $hasNested = true;
                    break;
                }
            }
            if ($hasNested) {
                $strip = $top . '/';
            }
        }

        // Pass 2: extract allowed files by streaming
        $written = [];
        foreach ($entries as [$i, $path, $size]) {
            if ($strip !== '') {
                if (!str_starts_with($path, $strip)) {
                    continue;
                }
                $path = substr($path, strlen($strip));
            }
            if ($path === '' || !$allow($path)) {
                throw new RuntimeException('File not allowed in update packages: ' . $path);
            }
            $target = $destReal . '/' . $path;
            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                throw new RuntimeException('Cannot create ' . dirname($path));
            }
            $realDir = realpath($dir);
            if ($realDir === false || !str_starts_with($realDir . '/', $destReal . '/')) {
                throw new RuntimeException('Path escapes staging directory: ' . $path);
            }
            $in = $zip->getStream($zip->getNameIndex($i));
            $out = fopen($target, 'wb');
            if (!$in || !$out) {
                throw new RuntimeException('Cannot extract ' . $path);
            }
            $copied = stream_copy_to_stream($in, $out, $size + 1);
            fclose($in);
            fclose($out);
            if ($copied !== $size) {
                throw new RuntimeException('Size mismatch while extracting ' . $path);
            }
            $written[] = $path;
        }
        $zip->close();
        return $written;
    }
}
