<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Console\Scaffold;

use Glueful\Console\Commands\Event\CreateListenerCommand;

/**
 * event:listener used to print `addListener(Event::class, [Listener::class, 'handle'])` — a
 * class-string/instance-method pair that is not a valid callable. The generated listener now
 * carries #[AsListener(Event::class)] and the hint is `$events->subscribe(Listener::class)`,
 * which this test proves registers and handles a dispatched event.
 */
final class CreateListenerCommandTest extends ScaffoldCommandTestCase
{
    public function testGeneratedListenerSubscribesAndHandlesTheEvent(): void
    {
        $tester = $this->tester(CreateListenerCommand::class);
        $event = 'App\\Events\\OrderPlacedProbe';

        $status = $tester->execute(['name' => 'OrderPlacedProbeListener', '--event' => $event]);
        $display = $tester->getDisplay();

        self::assertSame(0, $status, $display);
        $file = $this->base . '/app/Events/Listeners/OrderPlacedProbeListener.php';
        self::assertFileExists($file, $display);
        $source = (string) file_get_contents($file);
        self::assertStringContainsString('#[AsListener(OrderPlacedProbe::class)]', $source);
        self::assertStringContainsString('subscribe(OrderPlacedProbeListener::class)', $display);
        self::assertStringNotContainsString("::class, 'handle'])", $display);
        self::assertStringNotContainsString("::class, 'handle']", $source);
        $this->assertLints($file);

        // Follow the printed hint for real: subscribe, dispatch, and observe the handler run.
        $out = $this->runInChild(<<<PHP
eval('namespace App\\Events; final class OrderPlacedProbe {}');
    require {$this->export($file)};
    \$logger = new class extends Psr\\Log\\AbstractLogger {
        public array \$records = [];
        public function log(\$level, \\Stringable|string \$message, array \$context = []): void
        {
            \$this->records[] = [\$message, \$context['event'] ?? null];
        }
    };
    \$container = new class (\$logger) implements Psr\\Container\\ContainerInterface {
        public function __construct(private object \$logger) {}
        public function get(string \$id): mixed
        {
            if (\$id === Psr\\Log\\LoggerInterface::class) { return \$this->logger; }
            throw new class ("no \$id") extends RuntimeException implements Psr\\Container\\NotFoundExceptionInterface {};
        }
        public function has(string \$id): bool { return \$id === Psr\\Log\\LoggerInterface::class; }
    };
    \$provider = new Glueful\\Events\\ListenerProvider();
    \$events = new Glueful\\Events\\EventService(new Glueful\\Events\\EventDispatcher(\$provider), \$provider, \$container);
    \$events->subscribe(App\\Events\\Listeners\\OrderPlacedProbeListener::class);
    \$events->dispatch(new App\\Events\\OrderPlacedProbe());
    echo json_encode(\$logger->records);
PHP);

        self::assertSame('[["Event handled","App\\\\Events\\\\OrderPlacedProbe"]]', $out);
    }

    public function testWithoutAnEventTheHintStillNamesAWorkingRegistration(): void
    {
        $tester = $this->tester(CreateListenerCommand::class);

        $status = $tester->execute(['name' => 'AuditListener']);
        $display = $tester->getDisplay();

        self::assertSame(0, $status, $display);
        self::assertStringContainsString('#[AsListener(YourEvent::class)]', $display);
        self::assertStringContainsString('subscribe(AuditListener::class)', $display);
        self::assertStringNotContainsString("::class, 'handle'])", $display);
        $this->assertLints($this->base . '/app/Events/Listeners/AuditListener.php');
    }

    private function export(string $value): string
    {
        return var_export($value, true);
    }
}
