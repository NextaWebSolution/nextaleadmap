<?php

declare(strict_types=1);

/*
 * Release tooling — run on YOUR build machine, never on the production server.
 *
 *   php tools/release.php keygen
 *       Creates an Ed25519 key pair. Put the PUBLIC key into config/update-public-key.txt (ships with the app).
 *       Keep the SECRET key offline (password manager / hardware vault). Never commit it.
 *
 *   php tools/release.php build [--out=dist]
 *       Packages the current tree (code only: no .env, storage, uploads, .git, node_modules) into
 *       dist/nextaleadmap-<VERSION>.zip.
 *
 *   NLM_SIGNING_KEY=<secret b64> php tools/release.php sign dist/nextaleadmap-1.1.0.zip --notes="Fixes" [--min-version=1.0.0]
 *       Writes dist/manifest.json with checksum + Ed25519 signature. Upload both files to your update server
 *       (HTTPS) and set Settings → Updates → Update manifest URL to …/manifest.json.
 *
 *   php tools/release.php verify dist/manifest.json dist/nextaleadmap-1.1.0.zip
 */

if (PHP_SAPI !== 'cli') {
    exit;
}
require dirname(__DIR__) . '/bootstrap.php';

use App\Services\Update\ManifestVerifier;
use App\Services\Update\Updater;

$cmd = $argv[1] ?? '';
$opts = [];
$pos = [];
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? '1';
    } else {
        $pos[] = $a;
    }
}
$root = dirname(__DIR__);

switch ($cmd) {
    case 'keygen':
        $kp = sodium_crypto_sign_keypair();
        echo "PUBLIC  (config/update-public-key.txt):\n" . base64_encode(sodium_crypto_sign_publickey($kp)) . "\n\n";
        echo "SECRET  (keep offline, export as NLM_SIGNING_KEY when signing):\n" . base64_encode(sodium_crypto_sign_secretkey($kp)) . "\n";
        break;

    case 'build':
        $version = trim((string) file_get_contents("$root/VERSION"));
        $outDir = $opts['out'] ?? "$root/dist";
        @mkdir($outDir, 0755, true);
        $zipFile = "$outDir/nextaleadmap-$version.zip";
        @unlink($zipFile);
        $zip = new ZipArchive();
        $zip->open($zipFile, ZipArchive::CREATE);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        $n = 0;
        foreach ($it as $f) {
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
            if ($f->isLink() || !$f->isFile() || !Updater::allowedPath($rel) || str_starts_with($rel, 'dist/') || str_contains($rel, 'node_modules/')) {
                continue;
            }
            $zip->addFile($f->getPathname(), $rel);
            $n++;
        }
        $zip->close();
        echo "✓ $n files → $zipFile\n";
        break;

    case 'sign':
        $zipFile = $pos[0] ?? '';
        $sk = getenv('NLM_SIGNING_KEY') ?: '';
        if (!is_file($zipFile) || $sk === '') {
            fwrite(STDERR, "Usage: NLM_SIGNING_KEY=… php tools/release.php sign <zip> --notes=\"…\"\n");
            exit(1);
        }
        $z = new ZipArchive();
        $z->open($zipFile);
        $version = trim((string) $z->getFromName('VERSION'));
        $z->close();
        $manifest = [
            'version' => $version,
            'release_date' => gmdate('Y-m-d'),
            'minimum_php' => $opts['min-php'] ?? '8.3',
            'archive' => basename($zipFile),
            'checksum' => hash_file('sha256', $zipFile),
            'size' => filesize($zipFile),
            'release_notes' => $opts['notes'] ?? '',
        ];
        if (!empty($opts['min-version'])) {
            $manifest['min_version'] = $opts['min-version'];
        }
        $manifest['signature'] = ManifestVerifier::sign($manifest, $sk);
        $out = dirname($zipFile) . '/manifest.json';
        file_put_contents($out, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        echo "✓ Signed $version → $out\n";
        break;

    case 'verify':
        [$mf, $zipFile] = $pos + [null, null];
        $m = json_decode((string) file_get_contents((string) $mf), true);
        ManifestVerifier::fromFile("$root/config/update-public-key.txt")->verify($m);
        if ($zipFile && !hash_equals($m['checksum'], hash_file('sha256', $zipFile))) {
            fwrite(STDERR, "✗ checksum mismatch\n");
            exit(1);
        }
        echo "✓ Signature valid" . ($zipFile ? ' and checksum matches' : '') . "\n";
        break;

    default:
        echo "Commands: keygen | build | sign <zip> | verify <manifest> [zip]\n";
}
