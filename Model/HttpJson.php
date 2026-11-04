<?php
declare(strict_types=1);

namespace Furan\InfraHealth\Model;

/**
 * Minimal read-only JSON GET over cURL with hard timeouts, so a dead backend can't pin an FPM worker.
 */
class HttpJson
{
    private const CONNECT_TIMEOUT_MS = 1000;
    private const TIMEOUT_MS = 2500;
    private const MAX_BYTES = 8388608;

    /**
     * @return array{0: mixed, 1: float} Decoded body and elapsed milliseconds
     * @throws \RuntimeException
     */
    public function get(string $url, string $username = '', string $password = '', bool $verifyTls = true): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => self::CONNECT_TIMEOUT_MS,
            CURLOPT_TIMEOUT_MS => self::TIMEOUT_MS,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => $verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
        ]);
        if ($username !== '') {
            curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
        }

        $body = '';
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, string $chunk) use (&$body): int {
            $body .= $chunk;
            // Returning a short count aborts the transfer once the cap is hit.
            return strlen($body) > self::MAX_BYTES ? 0 : strlen($chunk);
        });

        $start = hrtime(true);
        $ok = curl_exec($ch);
        $elapsed = (hrtime(true) - $start) / 1e6;
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($ok === false) {
            throw new \RuntimeException(strlen($body) > self::MAX_BYTES
                ? 'Response larger than ' . (self::MAX_BYTES >> 20) . ' MB'
                : 'Connection failed: ' . $error);
        }
        if ($code >= 400) {
            throw new \RuntimeException(sprintf('HTTP %d from %s', $code, parse_url($url, PHP_URL_PATH) ?: '/'));
        }
        $decoded = json_decode((string)$body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON response');
        }
        return [$decoded, $elapsed];
    }
}
