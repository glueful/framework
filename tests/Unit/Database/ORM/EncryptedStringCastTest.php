<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\ORM;

use Glueful\Database\ORM\Casts\AsEncryptedString;
use Glueful\Database\ORM\Model;
use Glueful\Encryption\EncryptionService;
use Glueful\Tests\Unit\Database\ORM\Support\SqliteOrmHarness;
use PHPUnit\Framework\TestCase;

/**
 * AsEncryptedString writes through the framework EncryptionService (AES-256-GCM, APP_KEY,
 * key rotation, AAD "<table>.<column>") and still reads values written by the legacy
 * ENCRYPTION_KEY / AES-256-CBC implementation, re-encrypting them on their next save.
 */
final class EncryptedStringCastTest extends TestCase
{
    use SqliteOrmHarness;

    private const LEGACY_KEY = 'legacy-encryption-key-0123456789';

    private EncryptionService $encryption;

    protected function setUp(): void
    {
        $this->bootSqliteOrm();
        $this->context->overrideConfig('encryption.key', 'base64:' . base64_encode(random_bytes(32)));
        $this->encryption = new EncryptionService($this->context);
        $this->services[EncryptionService::class] = $this->encryption;

        AsEncryptedString::setKey(self::LEGACY_KEY);

        $this->connection->getSchemaBuilder()->createTable('secret_notes', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->text('secret')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(AsEncryptedString::class, 'key'))->setValue(null, null);
    }

    private function legacyEncrypt(string $plain): string
    {
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', self::LEGACY_KEY, OPENSSL_RAW_DATA, $iv);

        return base64_encode($iv . $cipher);
    }

    private function storedSecret(int|string $id): string
    {
        $row = $this->connection->table('secret_notes')->where('id', $id)->first();
        self::assertIsArray($row);

        return (string) $row['secret'];
    }

    public function testNewValuesAreEncryptedWithEncryptionService(): void
    {
        $note = SecretNote::create($this->context, ['secret' => 'top secret']);

        $stored = $this->storedSecret($note->getKey());
        self::assertTrue($this->encryption->isEncrypted($stored));
        self::assertSame('top secret', $this->encryption->decrypt($stored, 'secret_notes.secret'));

        $fresh = SecretNote::find($this->context, $note->getKey());
        self::assertNotNull($fresh);
        self::assertSame('top secret', $fresh->secret);
    }

    public function testLegacyCbcValuesStillDecrypt(): void
    {
        $this->connection->table('secret_notes')->insert(['secret' => $this->legacyEncrypt('old secret')]);

        $note = SecretNote::query($this->context)->first();

        self::assertNotNull($note);
        self::assertSame('old secret', $note->secret);
    }

    public function testLegacyValueIsReEncryptedWithEncryptionServiceOnSave(): void
    {
        $this->connection->table('secret_notes')->insert(['secret' => $this->legacyEncrypt('old secret')]);
        $note = SecretNote::query($this->context)->first();
        self::assertNotNull($note);

        $note->secret = $note->secret;
        self::assertTrue($note->save());

        $stored = $this->storedSecret($note->getKey());
        self::assertTrue($this->encryption->isEncrypted($stored));
        self::assertSame('old secret', SecretNote::find($this->context, $note->getKey())?->secret);
    }

    public function testValuesEncryptedUnderAPreviousKeyStillDecrypt(): void
    {
        $note = SecretNote::create($this->context, ['secret' => 'rotated']);

        // Rotate: the old key becomes a previous key, a new APP_KEY takes over.
        $rotated = new \Glueful\Bootstrap\ApplicationContext(sys_get_temp_dir(), 'testing');
        $rotated->overrideConfig('encryption.key', 'base64:' . base64_encode(random_bytes(32)));
        $rotated->overrideConfig('encryption.previous_keys', [
            (string) $this->context->getConfig('encryption.key'),
        ]);
        $this->services[EncryptionService::class] = new EncryptionService($rotated);

        self::assertSame('rotated', SecretNote::find($this->context, $note->getKey())?->secret);
    }

    public function testNullStaysNull(): void
    {
        $note = SecretNote::create($this->context, ['secret' => null]);

        self::assertNull(SecretNote::find($this->context, $note->getKey())?->secret);
    }
}

class SecretNote extends Model
{
    protected string $table = 'secret_notes';

    /** @var array<string> */
    protected array $fillable = ['secret'];

    /** @var array<string, string> */
    protected array $casts = ['secret' => AsEncryptedString::class];
}
