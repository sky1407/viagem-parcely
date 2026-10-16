<?php

declare(strict_types=1);

namespace App\Import;

use RuntimeException;

final class Downloader
{
    public function __construct(private readonly string $cacheDir)
    {
    }

    public function fetch(string $url): string
    {
        $fileName = basename((string) parse_url($url, PHP_URL_PATH));
        if ($fileName === '') {
            throw new RuntimeException("Cannot derive file name from URL: {$url}");
        }

        $path = $this->cacheDir . '/' . $fileName;
        if (is_file($path)) {
            return $path;
        }

        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
            throw new RuntimeException("Cannot create directory: {$this->cacheDir}");
        }

        $tempPath = $path . '.part';
        $file = fopen($tempPath, 'wb');
        if ($file === false) {
            throw new RuntimeException("Cannot open file for writing: {$tempPath}");
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FILE => $file,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_FAILONERROR => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 300,
        ]);
        $success = curl_exec($curl);
        $error = curl_error($curl);
        fclose($file);

        if ($success === false) {
            unlink($tempPath);
            throw new RuntimeException("Download failed: {$url} ({$error})");
        }

        if (!rename($tempPath, $path)) {
            throw new RuntimeException("Cannot move downloaded file to: {$path}");
        }

        return $path;
    }
}
