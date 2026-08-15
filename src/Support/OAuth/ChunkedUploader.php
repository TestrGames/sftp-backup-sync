<?php

namespace Lisak\SftpBackupSync\Support\OAuth;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Shared chunked-upload sender for resumable upload sessions. Both Microsoft
 * Graph and the Google Drive API use the same convention once you have a
 * session URL: PUT successive byte ranges with a `Content-Range` header,
 * no Authorization header needed (the session URL itself is pre-authorized).
 */
class ChunkedUploader
{
    // 10 MiB: a clean multiple of both Microsoft's required 320 KiB alignment
    // and Google's recommended 256 KiB alignment.
    private const CHUNK_SIZE = 10485760;

    public static function send(string $sessionUrl, string $filePath): void
    {
        $totalSize = filesize($filePath);
        throw_unless($totalSize !== false, new RuntimeException('Could not determine backup file size.'));

        if ($totalSize === 0) {
            Http::withHeaders(['Content-Range' => 'bytes */0'])->put($sessionUrl);

            return;
        }

        $handle = fopen($filePath, 'rb');
        throw_unless($handle, new RuntimeException('Could not open backup file for chunked upload.'));

        try {
            $offset = 0;

            while ($offset < $totalSize) {
                $chunk = fread($handle, self::CHUNK_SIZE);
                throw_if($chunk === false, new RuntimeException('Could not read backup chunk.'));

                $length = strlen($chunk);
                $end = $offset + $length - 1;

                $response = Http::withBody($chunk, 'application/octet-stream')
                    ->withHeaders([
                        'Content-Length' => (string) $length,
                        'Content-Range' => "bytes {$offset}-{$end}/{$totalSize}",
                    ])
                    ->timeout(120)
                    ->put($sessionUrl);

                throw_unless(
                    in_array($response->status(), [200, 201, 202], true),
                    new RuntimeException("Chunk upload failed: HTTP {$response->status()} {$response->body()}"),
                );

                $offset += $length;
            }
        } finally {
            fclose($handle);
        }
    }
}
