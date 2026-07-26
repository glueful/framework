<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ProtectedProviders;
use PHPUnit\Framework\TestCase;

/**
 * The protected-provider refusal (modules-not-extensions spec §8.3): config-driven, consulted
 * by every generic activation-mutation surface ABOVE the policy-free ExtensionStateWriter.
 */
final class ProtectedProvidersTest extends TestCase
{
    /** @param array<string, mixed> $protected */
    private function context(array $protected): ApplicationContext
    {
        $context = ApplicationContext::forTesting(
            sys_get_temp_dir() . '/glueful-protected-' . uniqid('', true)
        );
        $context->mergeConfigDefaults('extensions', ['protected' => $protected]);

        return $context;
    }

    public function testUnlistedProviderIsNotRefused(): void
    {
        $context = $this->context([
            'Vendor\\Tenancy\\EnforcementProvider' => [
                'reason' => 'Managed by the tenancy enablement flow.',
                'managed_by' => 'glueful/tenancy enablement',
            ],
        ]);

        self::assertNull(ProtectedProviders::refusalFor($context, 'Vendor\\Other\\Provider'));
    }

    public function testListedProviderRefusesWithReasonAndOwner(): void
    {
        $context = $this->context([
            'Vendor\\Tenancy\\EnforcementProvider' => [
                'reason' => 'Managed by the tenancy enablement flow.',
                'managed_by' => 'glueful/tenancy enablement',
            ],
        ]);

        $refusal = ProtectedProviders::refusalFor($context, 'Vendor\\Tenancy\\EnforcementProvider');
        self::assertNotNull($refusal);
        self::assertStringContainsString('Managed by the tenancy enablement flow.', $refusal);
        self::assertStringContainsString('glueful/tenancy enablement', $refusal);
    }

    public function testMalformedEntryStillRefusesWithAGenericReason(): void
    {
        $context = $this->context([
            'Vendor\\Tenancy\\EnforcementProvider' => ['managed_by' => 'glueful/tenancy enablement'],
        ]);

        $refusal = ProtectedProviders::refusalFor($context, 'Vendor\\Tenancy\\EnforcementProvider');
        self::assertNotNull($refusal);
        self::assertStringContainsString('managed outside', $refusal);
        self::assertStringContainsString('glueful/tenancy enablement', $refusal);
    }

    public function testEntryThatIsNotAnArrayStillRefuses(): void
    {
        $context = $this->context(['Vendor\\Tenancy\\EnforcementProvider' => true]);

        self::assertNotNull(
            ProtectedProviders::refusalFor($context, 'Vendor\\Tenancy\\EnforcementProvider')
        );
    }
}
