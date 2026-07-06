<?php

declare(strict_types=1);

namespace Glueful\Routing;

use Glueful\Security\SecurityHeaders;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Mime\MimeTypes;

/**
 * Serves compiled SPA/frontend bundles mounted via ServiceProvider::serveFrontend().
 *
 * Registered as [SpaMountController::class, 'root'|'asset'] so the route table
 * carries no closures and stays cacheable. The mount a request belongs to is
 * resolved from the request path via {@see FrontendMountRegistry} (longest-prefix
 * match), NOT from a route parameter — so one mount-agnostic controller serves
 * any number of mounts (/admin, /portal, …). The asset/index serving behaviour
 * (mime, security headers, ETag/Last-Modified, cache split, path-traversal
 * rejection, SPA deep-link fallback) is identical to the previous closure-based
 * implementation.
 */
final class SpaMountController
{
    public function __construct(private readonly FrontendMountRegistry $mounts)
    {
    }

    /**
     * Serve the SPA shell at a mount root (e.g. GET /admin).
     */
    public function root(Request $request): Response
    {
        $mount = $this->mounts->match($request->getPathInfo());
        if ($mount === null) {
            return new Response('', 404);
        }

        return $mount['spaFallback']
            ? $this->serveIndex($request, $mount['dir'])
            : new Response('', 404);
    }

    /**
     * Serve an asset, or the SPA shell as a deep-link fallback, under a mount
     * (e.g. GET /admin/{rest}).
     */
    public function asset(Request $request, string $rest): Response
    {
        $mount = $this->mounts->match($request->getPathInfo());
        if ($mount === null) {
            return new Response('', 404);
        }
        $realDir = $mount['dir'];
        $spaFallback = $mount['spaFallback'];

        if (headers_sent()) {
            return new Response('', 404);
        }

        $basename = basename($rest);
        if ($basename === '' || $basename[0] === '.' || str_ends_with(strtolower($basename), '.php')) {
            return new Response('', 404);
        }

        // Reject path-traversal sequences outright — a `..` segment must 404, never
        // fall through to the SPA shell (the realpath check below also rejects an
        // escaped *file*, but an extension-less traversal path would otherwise reach
        // the index.html fallback).
        if (preg_match('#(^|/)\.\.(/|$)#', $rest) === 1) {
            return new Response('', 404);
        }

        $requested = realpath($realDir . DIRECTORY_SEPARATOR . $rest);
        if (
            $requested !== false
            && str_starts_with($requested, $realDir . DIRECTORY_SEPARATOR)
            && is_file($requested)
        ) {
            return $this->serveAsset($request, $requested, $basename);
        }

        if (!$spaFallback) {
            return new Response('', 404);
        }
        // "A dot means an asset": a missing asset is a 404, never the HTML shell.
        if (pathinfo($rest, PATHINFO_EXTENSION) !== '') {
            return new Response('', 404);
        }
        return $this->serveIndex($request, $realDir);
    }

    /**
     * Stream a built asset with mime, security headers, the cache split,
     * ETag/Last-Modified and 304 handling.
     */
    private function serveAsset(Request $request, string $realPath, string $basename): Response
    {
        $mtime = filemtime($realPath) !== false ? filemtime($realPath) : time();
        $etag = md5_file($realPath) !== false ? md5_file($realPath) : sha1($realPath);

        $guesser = MimeTypes::getDefault();
        // Extension map FIRST: content sniffing cannot identify text formats
        // (css/js/svg carry no magic bytes — finfo calls them text/plain), and
        // these responses send X-Content-Type-Options: nosniff, so a wrong
        // type makes browsers REFUSE stylesheets and module scripts outright.
        // Sniffing remains the fallback for extensionless files.
        $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        $byExtension = $ext !== '' ? ($guesser->getMimeTypes($ext)[0] ?? null) : null;
        $mimeGuess = mime_content_type($realPath);
        $mime = $byExtension
            ?? $guesser->guessMimeType($realPath)
            ?? ($mimeGuess !== false ? $mimeGuess : 'application/octet-stream');

        $resp = new BinaryFileResponse($realPath);
        $resp->headers->set('Content-Type', $mime);
        foreach (SecurityHeaders::defaultStaticAssetHeaders() as $header => $value) {
            $resp->headers->set($header, $value);
        }
        $resp->headers->set('Cache-Control', $this->cacheControl($basename));
        $resp->setEtag('"' . $etag . '"');
        $resp->setLastModified((new \DateTimeImmutable())->setTimestamp($mtime));
        $resp->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $basename
        );
        $resp->isNotModified($request);
        return $resp;
    }

    /**
     * Serve index.html (200, no-cache, hardened headers, revalidatable).
     */
    private function serveIndex(Request $request, string $realDir): Response
    {
        $index = $realDir . DIRECTORY_SEPARATOR . 'index.html';
        if (!is_file($index)) {
            return new Response('', 404);
        }
        $resp = new BinaryFileResponse($index);
        $resp->headers->set('Content-Type', 'text/html; charset=UTF-8');
        foreach (SecurityHeaders::defaultStaticAssetHeaders() as $header => $value) {
            $resp->headers->set($header, $value);
        }
        $resp->headers->set('Cache-Control', 'no-cache');
        $mtime = filemtime($index) !== false ? filemtime($index) : time();
        $etag = md5_file($index) !== false ? md5_file($index) : sha1($index);
        $resp->setEtag('"' . $etag . '"');
        $resp->setLastModified((new \DateTimeImmutable())->setTimestamp($mtime));
        $resp->isNotModified($request);
        return $resp;
    }

    /**
     * Cache-Control for a served file: content-hashed assets are immutable;
     * everything else (incl. index.html) revalidates so deploys are seen.
     */
    private function cacheControl(string $basename): string
    {
        return preg_match('/[.\-_][A-Za-z0-9]{8,}\.[A-Za-z0-9]+$/', $basename) === 1
            ? 'public, max-age=31536000, immutable'
            : 'no-cache';
    }
}
