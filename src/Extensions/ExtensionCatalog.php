<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Http\Client;

/**
 * The installable / installed extension catalog.
 *
 * Browse = Packagist packages of type `glueful-extension` under the `glueful/`
 * vendor, hydrated for version + type re-verification, then cross-referenced with
 * the local install so each row carries a state. Installed = the local candidates
 * cross-referenced with the enabled allow-list.
 */
class ExtensionCatalog
{
    private const CACHE_KEY = 'ext_catalog:glueful';
    private const CACHE_TTL = 3600;
    private const VENDOR = 'glueful/';
    private const SEARCH_URL = 'https://packagist.org/search.json?type=glueful-extension&per_page=100';

    /** @param CacheStore<mixed> $cache */
    public function __construct(
        private ApplicationContext $context,
        private Client $http,
        private CacheStore $cache,
        private ExtensionManager $extensions,
    ) {
    }

    /**
     * @return list<array{package:string,description:string,version:?string,downloads:int,repository:string,state:string}>
     */
    public function catalog(bool $refresh = false): array
    {
        $packages = $refresh ? null : $this->cache->get(self::CACHE_KEY);
        if (!is_array($packages)) {
            $packages = $this->fetchFromPackagist();
            $this->cache->set(self::CACHE_KEY, $packages, self::CACHE_TTL);
        }

        $installed = $this->installedMap();
        $enabled = EnabledProviders::from($this->context);

        return array_map(function (array $p) use ($installed, $enabled): array {
            $state = 'available';
            if (isset($installed[$p['package']])) {
                $state = in_array($installed[$p['package']], $enabled, true) ? 'enabled' : 'installed';
            }
            return [...$p, 'state' => $state];
        }, $packages);
    }

    /**
     * @return list<array{package:string,provider:string,version:?string,label:string,state:string}>
     */
    public function installed(): array
    {
        $candidates = (new PackageManifest($this->context))->getCandidates();
        $enabled = EnabledProviders::from($this->context);
        $meta = $this->extensions->listMeta();

        $rows = [];
        $known = [];
        foreach ($candidates as $package => $candidate) {
            $known[] = $candidate->provider;
            $rows[] = [
                'package' => $package,
                'provider' => $candidate->provider,
                'version' => $candidate->version,
                'label' => is_string($meta[$candidate->provider]['name'] ?? null)
                    ? $meta[$candidate->provider]['name']
                    : $package,
                'state' => in_array($candidate->provider, $enabled, true) ? 'enabled' : 'available',
            ];
        }
        // enabled-but-missing: allow-listed provider with no installed candidate.
        foreach ($enabled as $provider) {
            if (!in_array($provider, $known, true)) {
                $rows[] = [
                    'package' => $provider,
                    'provider' => $provider,
                    'version' => null,
                    'label' => $provider,
                    'state' => 'enabled_missing',
                ];
            }
        }
        return $rows;
    }

    /** @return array<string,string> package => provider (installed candidates) */
    private function installedMap(): array
    {
        $out = [];
        foreach ((new PackageManifest($this->context))->getCandidates() as $package => $candidate) {
            $out[$package] = $candidate->provider;
        }
        return $out;
    }

    /**
     * @return list<array{package:string,description:string,version:?string,downloads:int,repository:string}>
     */
    private function fetchFromPackagist(): array
    {
        $rows = [];
        foreach ($this->searchNames() as $name => $summary) {
            $version = $this->hydrateVersion($name); // null when not a glueful-extension
            if ($version === false) {
                continue;
            }
            $rows[] = [
                'package' => $name,
                'description' => is_string($summary['description'] ?? null) ? $summary['description'] : '',
                'version' => $version,
                'downloads' => (int) ($summary['downloads'] ?? 0),
                'repository' => is_string($summary['repository'] ?? null) ? $summary['repository'] : '',
            ];
        }
        return $rows;
    }

    /** @return array<string,array<string,mixed>> name => summary, vendor-filtered */
    private function searchNames(): array
    {
        $url = self::SEARCH_URL;
        $out = [];
        while ($url !== null) {
            $json = $this->fetchJson($url);
            foreach ($this->asList($json['results'] ?? null) as $result) {
                $name = is_string($result['name'] ?? null) ? $result['name'] : '';
                if (str_starts_with($name, self::VENDOR)) {
                    $out[$name] = $result;
                }
            }
            $url = is_string($json['next'] ?? null) ? $json['next'] : null;
        }
        return $out;
    }

    /**
     * Latest stable version string, null when unknown, or false when the package
     * is NOT type `glueful-extension` (excluded from the catalog).
     *
     * @return string|null|false
     */
    private function hydrateVersion(string $name)
    {
        $json = $this->fetchJson("https://repo.packagist.org/p2/{$name}.json");
        $versions = $this->asList($json['packages'][$name] ?? null);
        if ($versions === []) {
            return false;
        }
        $latestStable = null;
        foreach ($versions as $version) {
            if (($version['type'] ?? null) !== 'glueful-extension') {
                return false; // type re-verification failed
            }
            $ver = is_string($version['version'] ?? null) ? $version['version'] : '';
            if ($latestStable === null && $ver !== '' && !str_contains($ver, 'dev')) {
                $latestStable = $ver; // p2 lists newest first
            }
        }
        return $latestStable;
    }

    /**
     * Seam over the HTTP client so catalog logic is unit-testable without HTTP.
     *
     * @return array<string,mixed>
     */
    protected function fetchJson(string $url): array
    {
        $decoded = $this->http->get($url)->json();
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param mixed $value
     * @return list<array<string,mixed>>
     */
    private function asList($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter($value, 'is_array'));
    }
}
