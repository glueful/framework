<?php

declare(strict_types=1);

namespace Glueful\Routing\Middleware;

use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Conditional Cache Middleware
 *
 * Compares client validators (If-None-Match / If-Modified-Since) with the
 * response ETag/Last-Modified to short-circuit 304 Not Modified for GET/HEAD.
 *
 * Params:
 *  [0] strategy: 'etag'|'last_modified'|'both' (default 'both')
 *  [1] autoGenerate: bool (default false) — when true, generate a weak ETag by hashing body if none provided
 */
final class ConditionalCacheMiddleware implements RouteMiddleware
{
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        // Only apply to safe methods
        $method = strtoupper($request->getMethod());
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        $strategy = isset($params[0]) && is_string($params[0]) ? strtolower($params[0]) : 'both';
        $autoGenerate = isset($params[1]) ? (bool) $params[1] : false;

        $response = $next($request);

        if (!$response instanceof SymfonyResponse) {
            return $response;
        }

        // Optionally generate an ETag if missing and response is cacheable-ish
        $etag = $response->headers->get('ETag');
        if ($etag === null && $autoGenerate === true && $method === 'GET') {
            // Use weak ETag from hash of body
            $content = (string) $response->getContent();
            $hash = \md5($content);
            $etag = 'W/"' . $hash . '"';
            $response->headers->set('ETag', $etag);
        }

        // ETag conditional handling
        if (($strategy === 'etag' || $strategy === 'both') && $etag !== null) {
            $ifNoneMatch = $request->headers->get('If-None-Match');
            if (is_string($ifNoneMatch) && $ifNoneMatch !== '') {
                if ($this->etagMatches($etag, $ifNoneMatch)) {
                    return $this->notModified($response);
                }
            }
        }

        // Last-Modified conditional handling
        if ($strategy === 'last_modified' || $strategy === 'both') {
            $lastModified = $response->headers->get('Last-Modified');
            $ifModifiedSince = $request->headers->get('If-Modified-Since');
            $hasLastModified = is_string($lastModified) && $lastModified !== '';
            $hasIfModifiedSince = is_string($ifModifiedSince) && $ifModifiedSince !== '';
            if ($hasLastModified && $hasIfModifiedSince) {
                $lm = @\strtotime($lastModified);
                $ims = @\strtotime($ifModifiedSince);
                if ($lm !== false && $ims !== false && $lm <= $ims) {
                    return $this->notModified($response);
                }
            }
        }

        return $response;
    }

    private function notModified(SymfonyResponse $original): SymfonyResponse
    {
        $resp = new SymfonyResponse(null, 304);
        // Preserve validators and caching headers
        foreach (['ETag', 'Last-Modified', 'Cache-Control', 'Expires', 'Vary'] as $h) {
            if ($original->headers->has($h)) {
                $resp->headers->set($h, (string) $original->headers->get($h));
            }
        }
        return $resp;
    }

    private function etagMatches(string $etag, string $ifNoneMatch): bool
    {
        $etag = $this->normalizeEtag($etag);

        // Multiple tags separated by commas
        $candidates = array_map('trim', explode(',', $ifNoneMatch));
        foreach ($candidates as $cand) {
            if ($cand === '*') {
                return true;
            }
            if ($this->normalizeEtag($cand) === $etag) {
                return true;
            }
        }
        return false;
    }

    private function normalizeEtag(string $value): string
    {
        $v = trim($value);
        // Strip weak validators prefix and surrounding quotes
        if (str_starts_with($v, 'W/')) {
            $v = substr($v, 2);
        }
        $v = trim($v);
        if (str_starts_with($v, '"') && str_ends_with($v, '"')) {
            $v = substr($v, 1, -1);
        }
        return $v;
    }
}
