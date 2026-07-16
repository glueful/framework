<?php

declare(strict_types=1);

namespace Glueful\Uploader\Contracts;

/**
 * AND/veto-composes the host application's primary BlobAccessPolicy with every
 * current contributor in a BlobAccessPolicyRegistry: authorization succeeds
 * only when the primary AND every registered contributor return true.
 *
 * The registry is held by reference, not snapshotted at construction time, so
 * a contributor registered after this composite (or the controller holding
 * it) was built is still enforced on the next call — this is what lets
 * extension boot() order stay irrelevant. Evaluation short-circuits on the
 * first denial, primary first, then contributors in registry insertion order.
 *
 * With an empty registry and the framework's NullBlobAccessPolicy as primary
 * (StorageProvider's default when nothing is bound), behavior is
 * byte-identical to having no policy at all.
 */
final class CompositeBlobAccessPolicy implements BlobAccessPolicy
{
    public function __construct(
        private BlobAccessPolicy $primary,
        private BlobAccessPolicyRegistry $registry,
    ) {
    }

    /** @param array<string,mixed> $blob */
    public function authorizeAccess(array $blob, BlobAccessContext $context): bool
    {
        if (!$this->primary->authorizeAccess($blob, $context)) {
            return false;
        }

        foreach ($this->registry->all() as $contributor) {
            if (!$contributor->authorizeAccess($blob, $context)) {
                return false;
            }
        }

        return true;
    }
}
