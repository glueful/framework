<?php

declare(strict_types=1);

namespace Glueful\Http;

use Symfony\Component\HttpFoundation\Request;

final class ContentNegotiator
{
    /**
     * Negotiate a response content type from the Accept header.
     * Falls back to the first supported type when no match is found.
     *
     * @param Request $request
     * @param array<int,string> $supported e.g. ['application/json','text/plain']
     */
    public static function negotiate(Request $request, array $supported = ['application/json']): string
    {
        $accept = (string) $request->headers->get('Accept', '');
        if ($accept === '' || $accept === '*/*') {
            return $supported[0];
        }

        // Simple matching on MIME types in descending q order
        $parts = array_map('trim', explode(',', $accept));
        $candidates = [];
        foreach ($parts as $p) {
            $q = 1.0;
            if (str_contains($p, ';q=')) {
                [$mime, $param] = explode(';', $p, 2);
                $p = trim($mime);
                $qStr = substr(strstr($param, 'q='), 2);
                $q = is_numeric($qStr) ? (float) $qStr : 1.0;
            }
            $candidates[] = ['mime' => strtolower($p), 'q' => $q];
        }
        usort($candidates, fn($a, $b) => $b['q'] <=> $a['q']);

        foreach ($candidates as $c) {
            $mime = $c['mime'];
            foreach ($supported as $s) {
                $s = strtolower($s);
                // Exact match
                if ($mime === $s) {
                    return $s;
                }
                // Top-level wildcards
                if ($mime === 'application/*' && str_starts_with($s, 'application/')) {
                    return $s;
                }
                if ($mime === 'text/*' && str_starts_with($s, 'text/')) {
                    return $s;
                }
                if ($mime === '*/*') {
                    return $s;
                }
                // Structured suffix, e.g., application/vnd.api+json should match application/json support
                if (str_contains($mime, '+json') && $s === 'application/json') {
                    return $s;
                }
                if (str_contains($mime, '+xml') && $s === 'application/xml') {
                    return $s;
                }
            }
        }

        return $supported[0];
    }
}
