<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Encryption;

use Glueful\Console\BaseCommand;
use Glueful\Encryption\EncryptionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'encryption:test',
    description: 'Verify that encryption is working correctly'
)]
class TestCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Testing encryption service...</info>');
        $output->writeln('');

        try {
            $service = new EncryptionService($this->getContext());
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed to initialize encryption service:</error>');
            $output->writeln('  ' . $e->getMessage());
            $output->writeln('');
            $output->writeln('<comment>Run "php glueful generate:key" to generate a valid key.</comment>');
            return self::FAILURE;
        }

        $tests = [
            'Basic encrypt/decrypt' => fn() => $this->testBasicEncryption($service),
            'Binary encrypt/decrypt' => fn() => $this->testBinaryEncryption($service),
            'AAD binding' => fn() => $this->testAadBinding($service),
            'Tamper detection' => fn() => $this->testTamperDetection($service),
            'Random nonce' => fn() => $this->testRandomNonce($service),
            'isEncrypted() detection' => fn() => $this->testIsEncrypted($service),
        ];

        $passed = 0;
        $failed = 0;

        foreach ($tests as $name => $test) {
            try {
                $test();
                $output->writeln("  <info>✓</info> {$name}");
                $passed++;
            } catch (\Throwable $e) {
                $output->writeln("  <error>✗</error> {$name}");
                $output->writeln("    <comment>{$e->getMessage()}</comment>");
                $failed++;
            }
        }

        $output->writeln('');

        if ($failed === 0) {
            $output->writeln("<info>All {$passed} tests passed. Encryption is working correctly.</info>");
            return self::SUCCESS;
        }

        $output->writeln("<error>{$failed} test(s) failed, {$passed} passed.</error>");
        return self::FAILURE;
    }

    private function testBasicEncryption(EncryptionService $service): void
    {
        $original = 'Hello, World! 🔐';
        $encrypted = $service->encrypt($original);
        $decrypted = $service->decrypt($encrypted);

        if ($decrypted !== $original) {
            throw new \RuntimeException('Decrypted value does not match original');
        }
    }

    private function testBinaryEncryption(EncryptionService $service): void
    {
        $original = random_bytes(64);
        $encrypted = $service->encryptBinary($original);
        $decrypted = $service->decryptBinary($encrypted);

        if ($decrypted !== $original) {
            throw new \RuntimeException('Decrypted binary does not match original');
        }
    }

    private function testAadBinding(EncryptionService $service): void
    {
        $encrypted = $service->encrypt('secret', 'user.ssn');

        // Should succeed with correct AAD
        $service->decrypt($encrypted, 'user.ssn');

        // Should fail with wrong AAD
        try {
            $service->decrypt($encrypted, 'user.api_key');
            throw new \RuntimeException('Decryption should have failed with wrong AAD');
        } catch (\Glueful\Encryption\Exceptions\DecryptionException $e) {
            // Expected
        }
    }

    private function testTamperDetection(EncryptionService $service): void
    {
        $encrypted = $service->encrypt('secret');

        // Tamper with the ciphertext
        $tampered = substr($encrypted, 0, -5) . 'XXXXX';

        try {
            $service->decrypt($tampered);
            throw new \RuntimeException('Decryption should have failed on tampered data');
        } catch (\Glueful\Encryption\Exceptions\DecryptionException $e) {
            // Expected
        }
    }

    private function testRandomNonce(EncryptionService $service): void
    {
        $value = 'same-value';
        $encrypted1 = $service->encrypt($value);
        $encrypted2 = $service->encrypt($value);

        if ($encrypted1 === $encrypted2) {
            throw new \RuntimeException('Encrypting same value should produce different output');
        }
    }

    private function testIsEncrypted(EncryptionService $service): void
    {
        $encrypted = $service->encrypt('value');

        if (!$service->isEncrypted($encrypted)) {
            throw new \RuntimeException('isEncrypted() should return true for encrypted data');
        }

        if ($service->isEncrypted('not-encrypted')) {
            throw new \RuntimeException('isEncrypted() should return false for plain text');
        }
    }
}
