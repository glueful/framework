<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions\Install;

use Glueful\Cache\Drivers\ArrayCacheDriver;
use Glueful\Extensions\Install\InstallJobStore;
use PHPUnit\Framework\TestCase;

final class InstallJobStoreTest extends TestCase
{
    public function test_lifecycle_create_run_finish(): void
    {
        $store = new InstallJobStore(new ArrayCacheDriver());

        $id = $store->create('glueful/aegis');
        $this->assertSame('queued', $store->get($id)['status']);
        $this->assertSame('glueful/aegis', $store->get($id)['package']);

        $store->markRunning($id);
        $store->appendOutput($id, "Resolving...\n");
        $store->appendOutput($id, "Installing glueful/aegis\n");
        $this->assertSame('running', $store->get($id)['status']);
        $this->assertStringContainsString('Installing glueful/aegis', $store->get($id)['output']);

        $store->finish($id, 'succeeded', 0, null, null);
        $rec = $store->get($id);
        $this->assertSame('succeeded', $rec['status']);
        $this->assertSame(0, $rec['exitCode']);
        $this->assertNotNull($rec['finishedAt']);
    }

    public function test_installed_not_enabled_terminal_state_carries_enable_error(): void
    {
        $store = new InstallJobStore(new ArrayCacheDriver());
        $id = $store->create('glueful/aegis');

        $store->finish($id, 'installed_not_enabled', 0, null, 'missing dependency');
        $rec = $store->get($id);
        $this->assertSame('installed_not_enabled', $rec['status']);
        $this->assertSame('missing dependency', $rec['enableError']);
        $this->assertNull($rec['error']);
    }

    public function test_output_is_capped_to_tail(): void
    {
        $store = new InstallJobStore(new ArrayCacheDriver());
        $id = $store->create('glueful/aegis');

        $store->appendOutput($id, str_repeat('a', 70000));
        $store->appendOutput($id, 'TAIL_MARKER');
        $out = $store->get($id)['output'];
        $this->assertLessThanOrEqual(65536, strlen($out));
        $this->assertStringContainsString('TAIL_MARKER', $out); // newest kept
    }

    public function test_unknown_job_returns_null_and_mutations_are_noops(): void
    {
        $store = new InstallJobStore(new ArrayCacheDriver());
        $this->assertNull($store->get('nope'));
        $store->markRunning('nope');       // must not throw
        $store->appendOutput('nope', 'x');  // must not throw
        $this->assertNull($store->get('nope'));
    }
}
