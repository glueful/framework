<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Uploader;

use Glueful\Uploader\Contracts\BlobAccessContext;
use Glueful\Uploader\Contracts\BlobAccessPolicy;
use Glueful\Uploader\Contracts\BlobAccessPolicyRegistry;
use Glueful\Uploader\Contracts\BlobAction;
use Glueful\Uploader\Contracts\CompositeBlobAccessPolicy;
use Glueful\Uploader\Contracts\NullBlobAccessPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Layer 3 / Task 1 — BlobAccessPolicyRegistry + CompositeBlobAccessPolicy.
 *
 * The composite gives StorageProvider a single BlobAccessPolicy to hand
 * UploadController: today's primary policy (bound BlobAccessPolicy, or the
 * Null fallback) AND-composed with every contributor an extension registers
 * into the shared registry. The composite holds the registry object itself
 * (not a snapshot), so registry->all() is read fresh on every call — a
 * contributor registered after the composite (or the controller) was built
 * is still enforced on the very next authorization check.
 */
final class BlobAccessPolicyCompositionTest extends TestCase
{
    public function testPrimaryOnlyAllowPassesThrough(): void
    {
        $composite = new CompositeBlobAccessPolicy(
            new FixedBlobAccessPolicy(true),
            new BlobAccessPolicyRegistry()
        );

        self::assertTrue($composite->authorizeAccess($this->blob(), $this->context()));
    }

    public function testPrimaryOnlyDenyPassesThrough(): void
    {
        $composite = new CompositeBlobAccessPolicy(
            new FixedBlobAccessPolicy(false),
            new BlobAccessPolicyRegistry()
        );

        self::assertFalse($composite->authorizeAccess($this->blob(), $this->context()));
    }

    public function testContributorOnlyDenyDenies(): void
    {
        $registry = new BlobAccessPolicyRegistry();
        $registry->register('deny-all', new FixedBlobAccessPolicy(false));

        $composite = new CompositeBlobAccessPolicy(new FixedBlobAccessPolicy(true), $registry);

        self::assertFalse($composite->authorizeAccess($this->blob(), $this->context()));
    }

    public function testCombinedDenialPrimaryDeniesEvenWhenContributorAllows(): void
    {
        $registry = new BlobAccessPolicyRegistry();
        $registry->register('allow-all', new FixedBlobAccessPolicy(true));

        $composite = new CompositeBlobAccessPolicy(new FixedBlobAccessPolicy(false), $registry);

        self::assertFalse($composite->authorizeAccess($this->blob(), $this->context()));
    }

    public function testCombinedDenialContributorDeniesEvenWhenPrimaryAllows(): void
    {
        $registry = new BlobAccessPolicyRegistry();
        $registry->register('deny-all', new FixedBlobAccessPolicy(false));

        $composite = new CompositeBlobAccessPolicy(new FixedBlobAccessPolicy(true), $registry);

        self::assertFalse($composite->authorizeAccess($this->blob(), $this->context()));
    }

    public function testDeterministicInsertionOrderShortCircuitsOnFirstDenial(): void
    {
        /** @var list<string> $log */
        $log = [];

        $primary = new RecordingBlobAccessPolicy($log, 'primary', true);
        $registry = new BlobAccessPolicyRegistry();
        $registry->register('a', new RecordingBlobAccessPolicy($log, 'a', true));
        $registry->register('b', new RecordingBlobAccessPolicy($log, 'b', false));
        $registry->register('c', new RecordingBlobAccessPolicy($log, 'c', true));

        $composite = new CompositeBlobAccessPolicy($primary, $registry);

        self::assertFalse($composite->authorizeAccess($this->blob(), $this->context()));
        // 'c' never runs — the composite short-circuits at the first false,
        // in registry insertion order, after the primary.
        self::assertSame(['primary', 'a', 'b'], $log);
    }

    public function testAllOnRegistryPreservesInsertionOrderRegardlessOfId(): void
    {
        $registry = new BlobAccessPolicyRegistry();
        $registry->register('z-first', new FixedBlobAccessPolicy(true));
        $registry->register('a-second', new FixedBlobAccessPolicy(true));

        self::assertSame(['z-first', 'a-second'], array_keys($registry->all()));
    }

    public function testDuplicateIdThrowsLogicException(): void
    {
        $registry = new BlobAccessPolicyRegistry();
        $registry->register('dup', new FixedBlobAccessPolicy(true));

        $this->expectException(\LogicException::class);
        $registry->register('dup', new FixedBlobAccessPolicy(false));
    }

    public function testHasReflectsRegisteredIds(): void
    {
        $registry = new BlobAccessPolicyRegistry();

        self::assertFalse($registry->has('contrib'));

        $registry->register('contrib', new FixedBlobAccessPolicy(true));

        self::assertTrue($registry->has('contrib'));
    }

    public function testNoContributorAndNullPrimaryIsByteIdenticalAllow(): void
    {
        $composite = new CompositeBlobAccessPolicy(new NullBlobAccessPolicy(), new BlobAccessPolicyRegistry());

        self::assertTrue($composite->authorizeAccess($this->blob(), $this->context()));
    }

    public function testLateRegistrationTakesEffectOnTheSameCompositeInstance(): void
    {
        $registry = new BlobAccessPolicyRegistry();
        $composite = new CompositeBlobAccessPolicy(new NullBlobAccessPolicy(), $registry);

        // Constructed before any contributor exists — must still allow.
        self::assertTrue($composite->authorizeAccess($this->blob(), $this->context()));

        // Register a denying contributor AFTER the composite (and, in the real
        // seam, the controller) was already built.
        $registry->register('late-contributor', new FixedBlobAccessPolicy(false));

        // The same composite instance now denies — proves the live registry
        // read, not a constructor-time snapshot.
        self::assertFalse($composite->authorizeAccess($this->blob(), $this->context()));
    }

    /** @return array<string,mixed> */
    private function blob(): array
    {
        return ['uuid' => 'blob123456', 'visibility' => 'private'];
    }

    private function context(): BlobAccessContext
    {
        return new BlobAccessContext(BlobAction::VIEW, 'usr123456789', false);
    }
}

final class FixedBlobAccessPolicy implements BlobAccessPolicy
{
    public function __construct(private bool $result)
    {
    }

    public function authorizeAccess(array $blob, BlobAccessContext $context): bool
    {
        return $this->result;
    }
}

final class RecordingBlobAccessPolicy implements BlobAccessPolicy
{
    /**
     * @param list<string> $log
     */
    public function __construct(private array &$log, private string $label, private bool $result)
    {
    }

    public function authorizeAccess(array $blob, BlobAccessContext $context): bool
    {
        $this->log[] = $this->label;
        return $this->result;
    }
}
