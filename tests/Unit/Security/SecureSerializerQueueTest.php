<?php

declare(strict_types=1);

namespace Glueful\Queue\Jobs {
    /**
     * Fixture: a class living under the queue jobs namespace covered by the
     * 'Glueful\Queue\Jobs\*' wildcard allowlist entry seeded by forQueue().
     */
    class FixtureJob
    {
        public function __construct(
            public string $name = 'demo',
            public int $count = 3
        ) {
        }
    }

    /**
     * Fixture: a Serializable (C:) class under the allowed wildcard namespace.
     */
    class FixtureSerializableJob implements \Serializable
    {
        public string $token = 'allowed';

        public function serialize(): string
        {
            return $this->token;
        }

        public function unserialize(string $data): void
        {
            $this->token = $data;
        }

        public function __serialize(): array
        {
            return ['token' => $this->token];
        }

        public function __unserialize(array $data): void
        {
            $this->token = $data['token'];
        }
    }
}

namespace Glueful\Danger {
    /**
     * Fixture: a class under no concrete entry, pattern, or safe prefix.
     */
    class Evil
    {
        public string $payload = 'pwn';
    }

    /**
     * Fixture: a Serializable (C:) class under a disallowed namespace.
     */
    class EvilSerializable implements \Serializable
    {
        public string $token = 'bad';

        public function serialize(): string
        {
            return $this->token;
        }

        public function unserialize(string $data): void
        {
            $this->token = $data;
        }

        public function __serialize(): array
        {
            return ['token' => $this->token];
        }

        public function __unserialize(array $data): void
        {
            $this->token = $data['token'];
        }
    }
}

namespace Acme\Jobs {
    /**
     * Fixture: a class under a generic vendor namespace used to prove
     * arbitrary prefix-wildcard entries work via the public constructor.
     */
    class AcmeJob
    {
        public string $label = 'acme';
    }
}

namespace Glueful\Tests\Unit\Security {

    use Acme\Jobs\AcmeJob;
    use Glueful\Danger\Evil;
    use Glueful\Danger\EvilSerializable;
    use Glueful\Queue\Jobs\FixtureJob;
    use Glueful\Queue\Jobs\FixtureSerializableJob;
    use Glueful\Security\SecureSerializer;
    use PHPUnit\Framework\TestCase;

    class SecureSerializerQueueTest extends TestCase
    {
        public function testWildcardJobRoundTripsToRealInstance(): void
        {
            $serializer = SecureSerializer::forQueue();

            $job = new FixtureJob('payroll', 7);
            $data = $serializer->serialize($job, true);

            $result = $serializer->unserialize($data);

            $this->assertInstanceOf(FixtureJob::class, $result);
            $this->assertNotInstanceOf(\__PHP_Incomplete_Class::class, $result);
            $this->assertSame('payroll', $result->name);
            $this->assertSame(7, $result->count);
        }

        public function testGenericPrefixPatternRoundTripsViaPublicConstructor(): void
        {
            $serializer = new SecureSerializer(['Acme\\Jobs\\*'], false);

            $job = new AcmeJob();
            $job->label = 'custom-label';
            $data = $serializer->serialize($job, true);

            $result = $serializer->unserialize($data);

            $this->assertInstanceOf(AcmeJob::class, $result);
            $this->assertNotInstanceOf(\__PHP_Incomplete_Class::class, $result);
            $this->assertSame('custom-label', $result->label);
        }

        public function testDisallowedClassIsBlocked(): void
        {
            $serializer = SecureSerializer::forQueue();

            $evil = new Evil();
            $data = $serializer->serialize($evil, true);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('not allowed for deserialization');

            $serializer->unserialize($data);
        }

        public function testSerializableClassUnderDisallowedNamespaceIsRejected(): void
        {
            $serializer = SecureSerializer::forQueue();

            $evil = new EvilSerializable();
            $data = $serializer->serialize($evil, true);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('not allowed for deserialization');

            $serializer->unserialize($data);
        }

        public function testSerializableClassUnderAllowedPatternRoundTrips(): void
        {
            $serializer = SecureSerializer::forQueue();

            $job = new FixtureSerializableJob();
            $job->token = 'secret-token';
            $data = $serializer->serialize($job, true);

            $result = $serializer->unserialize($data);

            $this->assertInstanceOf(FixtureSerializableJob::class, $result);
            $this->assertNotInstanceOf(\__PHP_Incomplete_Class::class, $result);
            $this->assertSame('secret-token', $result->token);
        }

        public function testExactNonWildcardAllowlistingStillWorks(): void
        {
            $serializer = new SecureSerializer([\DateTimeImmutable::class], true);

            $date = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
            $data = $serializer->serialize($date, true);

            $result = $serializer->unserialize($data);

            $this->assertInstanceOf(\DateTimeImmutable::class, $result);
            $this->assertSame('2026-01-01', $result->format('Y-m-d'));
        }

        public function testNestedJobArrayRoundTripsLikeQueueJob(): void
        {
            // Mirrors how Job::serialize() wraps the job in an array payload.
            $serializer = SecureSerializer::forQueue();

            $props = [
                'class' => FixtureJob::class,
                'job' => new FixtureJob('nested', 1),
            ];
            $data = $serializer->serialize($props, true);

            $result = $serializer->unserialize($data, [FixtureJob::class]);

            $this->assertIsArray($result);
            $this->assertInstanceOf(FixtureJob::class, $result['job']);
            $this->assertSame('nested', $result['job']->name);
        }
    }
}
