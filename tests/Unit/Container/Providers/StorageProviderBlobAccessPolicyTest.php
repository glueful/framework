<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Container\Providers;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Controllers\UploadController;
use Glueful\Framework;
use Glueful\Routing\RouteManifest;
use Glueful\Uploader\Contracts\BlobAccessContext;
use Glueful\Uploader\Contracts\BlobAccessPolicy;
use Glueful\Uploader\Contracts\BlobAccessPolicyRegistry;
use Glueful\Uploader\Contracts\BlobAction;
use Glueful\Uploader\Contracts\CompositeBlobAccessPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Layer 3 / Task 1 — proves StorageProvider actually wires the composition
 * seam end-to-end through the real DI container, not just at the unit level:
 *
 *  - BlobAccessPolicyRegistry is a normal SHARED service (same instance on
 *    every resolution).
 *  - UploadController is constructed with a CompositeBlobAccessPolicy that
 *    holds that exact shared registry instance.
 *  - Because it's the live registry (not a snapshot), registering a
 *    contributor AFTER the controller has already been resolved from the
 *    container still changes the next authorization outcome.
 *
 * Reuses the Framework-boot harness established by
 * StorageProviderFileUploaderMediaTest (real boot + file-backed SQLite +
 * local `uploads` disk + BaseRepository shared-connection reset + a
 * container-bound `request` carrying a `user` attribute).
 */
final class StorageProviderBlobAccessPolicyTest extends TestCase
{
    private string $appPath;
    private string $uploadsRoot;
    private string $dbFile;
    private Application $app;
    private ApplicationContext $context;

    protected function setUp(): void
    {
        RouteManifest::reset();

        $this->appPath = sys_get_temp_dir() . '/glueful-blob-policy-' . uniqid('', true);
        $this->uploadsRoot = $this->appPath . '/disk';
        $this->dbFile = $this->appPath . '/app.sqlite';
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);
        mkdir($this->uploadsRoot, 0755, true);

        // File-backed SQLite so every Connection shares one database.
        putenv('DB_DATABASE=' . $this->dbFile);
        putenv('DB_SQLITE_DATABASE=' . $this->dbFile);
        $_ENV['DB_DATABASE'] = $this->dbFile;
        $_ENV['DB_SQLITE_DATABASE'] = $this->dbFile;

        file_put_contents(
            $cfg . '/app.php',
            "<?php\nreturn ['name' => 'T', 'version_full' => '1.0.0', 'env' => 'testing', 'debug' => true];\n"
        );
        file_put_contents(
            $cfg . '/database.php',
            "<?php\nreturn ['engine' => 'sqlite', 'sqlite' => ['primary' => '" . $this->dbFile . "'], "
            . "'pooling' => ['enabled' => false]];\n"
        );
        file_put_contents(
            $cfg . '/cache.php',
            "<?php\nreturn ['enabled' => true, 'default' => 'array', 'stores' => ['array' => ['driver' => 'array']]];\n"
        );
        file_put_contents($cfg . '/security.php', "<?php\nreturn ['csrf' => ['enabled' => false]];\n");
        file_put_contents($cfg . '/session.php', "<?php\nreturn ['jwt_key' => 'test'];\n");

        file_put_contents(
            $cfg . '/storage.php',
            "<?php\nreturn ['default' => 'uploads', 'disks' => ['uploads' => "
            . "['driver' => 'local', 'root' => '" . $this->uploadsRoot . "', 'visibility' => 'private']]];\n"
        );
        file_put_contents(
            $cfg . '/uploads.php',
            "<?php\nreturn ['enabled' => true, 'disk' => 'uploads', 'path_prefix' => '', "
            . "'allowed_types' => ['image/*', 'video/*', 'audio/*'], 'max_size' => 10485760];\n"
        );

        $this->resetSharedConnection();

        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);
        $this->context = $this->app->getContext();

        $this->runBlobsMigration();

        $request = new Request();
        $request->attributes->set('user', ['uuid' => 'usr123456789']);
        /** @var \Glueful\Container\Container $container */
        $container = $this->context->getContainer();
        $container->load([
            'request' => new ValueDefinition('request', $request),
        ]);
    }

    protected function tearDown(): void
    {
        putenv('DB_DATABASE=:memory:');
        putenv('DB_SQLITE_DATABASE');
        $_ENV['DB_DATABASE'] = ':memory:';
        unset($_ENV['DB_SQLITE_DATABASE']);

        if (isset($this->appPath) && is_dir($this->appPath)) {
            $this->recursiveRemove($this->appPath);
        }
        parent::tearDown();
    }

    public function testRegistryIsSharedAcrossResolutions(): void
    {
        /** @var \Glueful\Container\Container $container */
        $container = $this->context->getContainer();

        $first = $container->get(BlobAccessPolicyRegistry::class);
        $second = $container->get(BlobAccessPolicyRegistry::class);

        self::assertInstanceOf(BlobAccessPolicyRegistry::class, $first);
        self::assertSame($first, $second, 'BlobAccessPolicyRegistry must be a shared singleton.');
    }

    public function testControllerReceivesTheLiveCompositeHoldingTheSharedRegistry(): void
    {
        /** @var \Glueful\Container\Container $container */
        $container = $this->context->getContainer();

        /** @var UploadController $controller */
        $controller = $container->get(UploadController::class);

        $policy = $this->readPrivateProperty($controller, 'accessPolicy');
        self::assertInstanceOf(CompositeBlobAccessPolicy::class, $policy);

        $registryInsideComposite = $this->readPrivateProperty($policy, 'registry');
        $registryFromContainer = $container->get(BlobAccessPolicyRegistry::class);

        self::assertSame(
            $registryFromContainer,
            $registryInsideComposite,
            'The composite must hold the exact container-bound registry instance, not a copy.'
        );
    }

    /**
     * Nothing is bound (no primary BlobAccessPolicy, zero contributors) so the
     * controller's composite must behave exactly like the old unwrapped
     * NullBlobAccessPolicy: always allow.
     */
    public function testUnboundIsByteIdenticalAllow(): void
    {
        /** @var \Glueful\Container\Container $container */
        $container = $this->context->getContainer();

        /** @var UploadController $controller */
        $controller = $container->get(UploadController::class);
        $policy = $this->readPrivateProperty($controller, 'accessPolicy');

        self::assertTrue($policy->authorizeAccess(
            ['uuid' => 'blob123456', 'visibility' => 'private'],
            new BlobAccessContext(BlobAction::VIEW, 'usr123456789', false)
        ));
    }

    /**
     * The key liveness proof: register a denying contributor into the
     * container's shared registry AFTER UploadController has already been
     * resolved (and therefore its composite already constructed) — the very
     * next authorization call must still see it.
     */
    public function testLateExtensionRegistrationAffectsAnAlreadyResolvedController(): void
    {
        /** @var \Glueful\Container\Container $container */
        $container = $this->context->getContainer();

        /** @var UploadController $controller */
        $controller = $container->get(UploadController::class);
        $policy = $this->readPrivateProperty($controller, 'accessPolicy');

        $blob = ['uuid' => 'blob123456', 'visibility' => 'private'];
        $context = new BlobAccessContext(BlobAction::VIEW, 'usr123456789', false);

        self::assertTrue($policy->authorizeAccess($blob, $context));

        /** @var BlobAccessPolicyRegistry $registry */
        $registry = $container->get(BlobAccessPolicyRegistry::class);
        $registry->register('layer3-late-contributor', new class implements BlobAccessPolicy {
            public function authorizeAccess(array $blob, BlobAccessContext $context): bool
            {
                return false;
            }
        });

        self::assertFalse($policy->authorizeAccess($blob, $context));
    }

    private function readPrivateProperty(object $object, string $property): mixed
    {
        $ref = new \ReflectionProperty($object, $property);
        $ref->setAccessible(true);
        return $ref->getValue($object);
    }

    private function resetSharedConnection(): void
    {
        $ref = new \ReflectionClass(\Glueful\Repository\BaseRepository::class);
        if ($ref->hasProperty('sharedConnection')) {
            $prop = $ref->getProperty('sharedConnection');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }
    }

    private function runBlobsMigration(): void
    {
        $schema = \Glueful\Database\Connection::fromContext($this->context)->getSchemaBuilder();

        $schema->createTable('blobs', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('mime_type', 127);
            $table->bigInteger('size');
            $table->string('url', 2048);
            $table->string('storage_type', 20)->default('local');
            $table->string('visibility', 10)->default('private');
            $table->string('status', 20)->default('active');
            $table->string('created_by', 12);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();

            $table->unique('uuid');
        });
    }

    private function recursiveRemove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->recursiveRemove($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
