<?php
declare(strict_types=1);

namespace Furan\InfraHealth\Model;

/**
 * Strips credentials embedded in URLs or host strings before anything is shown.
 */
class Redactor
{
    public static function clean(string $text): string
    {
        // scheme://user:pass@host
        $text = (string)preg_replace('#([a-z][a-z0-9+.-]*://)[^/@\s]+@#i', '$1', $text);
        // user:pass@host without a scheme
        return (string)preg_replace('#(^|[\s,;(\'"])[^/@\s,;:\'"]+:[^/@\s,;\'"]*@#', '$1', $text);
    }
}
