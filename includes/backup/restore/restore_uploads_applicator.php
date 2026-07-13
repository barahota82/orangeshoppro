<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_staging_target.php';

/**
 * Extract full-disaster uploads.zip into staging uploads directory under restore work root.
 *
 * @return array{ok:bool,files_extracted:int,bytes_extracted:int,error:?string}
 */
function orange_restore_uploads_applicator_extract(string $zipPath, string $targetDir, ?callable $log = null): array
{
    if (!is_file($zipPath)) {
        return ['ok' => false, 'files_extracted' => 0, 'bytes_extracted' => 0, 'error' => 'Uploads zip missing'];
    }
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'files_extracted' => 0, 'bytes_extracted' => 0, 'error' => 'ZipArchive unavailable'];
    }
    if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        return ['ok' => false, 'files_extracted' => 0, 'bytes_extracted' => 0, 'error' => 'Cannot create uploads target directory'];
    }

    $log ??= static function (string $message): void {
        orange_restore_log($message);
    };
    $log('Uploads restore... START');

    $zip = new ZipArchive();
    $opened = $zip->open($zipPath);
    if ($opened !== true) {
        return ['ok' => false, 'files_extracted' => 0, 'bytes_extracted' => 0, 'error' => 'Cannot open uploads zip (code ' . (string) $opened . ')'];
    }

    $filesExtracted = 0;
    $bytesExtracted = 0;
    $targetReal = realpath($targetDir) ?: $targetDir;
    $targetNorm = strtolower(rtrim(str_replace('\\', '/', $targetReal), '/'));

    try {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === '') {
                continue;
            }
            $normalized = str_replace('\\', '/', $name);
            if (str_starts_with($normalized, '/') || preg_match('/(^|\/)\.\.(\/|$)/', $normalized) === 1) {
                throw new RuntimeException('Blocked zip traversal entry: ' . $name);
            }
            $dest = $targetReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
            $destNorm = strtolower(rtrim(str_replace('\\', '/', $dest), '/'));
            if ($destNorm !== $targetNorm && !str_starts_with($destNorm, $targetNorm . '/')) {
                throw new RuntimeException('Zip extract path escapes target directory: ' . $name);
            }
            if (str_ends_with($normalized, '/')) {
                if (!is_dir($dest) && !@mkdir($dest, 0775, true) && !is_dir($dest)) {
                    throw new RuntimeException('Cannot create directory from zip: ' . $name);
                }
                continue;
            }
            $parent = dirname($dest);
            if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new RuntimeException('Cannot create parent directory for zip entry: ' . $name);
            }
            $contents = $zip->getFromIndex($i);
            if ($contents === false) {
                throw new RuntimeException('Cannot read zip entry: ' . $name);
            }
            if (file_put_contents($dest, $contents) === false) {
                throw new RuntimeException('Cannot write zip entry: ' . $name);
            }
            $filesExtracted++;
            $bytesExtracted += strlen($contents);
        }
    } catch (Throwable $e) {
        $zip->close();

        return ['ok' => false, 'files_extracted' => $filesExtracted, 'bytes_extracted' => $bytesExtracted, 'error' => $e->getMessage()];
    }

    $zip->close();
    $log('Uploads restore... OK (files=' . (string) $filesExtracted . ', bytes=' . (string) $bytesExtracted . ')');

    return ['ok' => true, 'files_extracted' => $filesExtracted, 'bytes_extracted' => $bytesExtracted, 'error' => null];
}
