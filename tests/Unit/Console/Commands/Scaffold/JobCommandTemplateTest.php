<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console\Commands\Scaffold;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\Commands\Scaffold\JobCommand;
use Glueful\Queue\Job;
use Glueful\Queue\JobHandlerResolver;
use PHPUnit\Framework\TestCase;

/**
 * The job `scaffold:job` wrote dropped the context (its constructor took only $data), so
 * $this->context was null in every scaffolded job, and `--unique` used a trait that doesn't exist.
 * The generated job must load, keep the context the worker passes, and report its max attempts.
 */
final class JobCommandTemplateTest extends TestCase
{
    public function testGeneratedJobLoadsKeepsTheContextAndReportsItsAttempts(): void
    {
        $base = sys_get_temp_dir() . '/glueful-job-scaffold-' . bin2hex(random_bytes(4));
        mkdir($base . '/app', 0777, true);
        $context = new ApplicationContext($base);

        $className = 'ScaffoldJobProbe' . bin2hex(random_bytes(4));
        $command = new JobCommand(null, $context);
        $generate = new \ReflectionMethod(JobCommand::class, 'generateJobClass');
        /** @var string $source */
        $source = $generate->invoke($command, $className, 5);

        $file = $base . '/' . $className . '.php';
        file_put_contents($file, $source);

        try {
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $lint, $exit);
            self::assertSame(0, $exit, implode("\n", $lint));

            require $file;
            $fqcn = 'App\\Jobs\\' . $className;

            // The worker builds handlers through the resolver, which passes the data and context.
            $job = JobHandlerResolver::resolve($fqcn, ['key' => 'value'], $context);
            self::assertInstanceOf(Job::class, $job);
            self::assertSame(5, $job->getMaxAttempts());
            self::assertSame(['key' => 'value'], $job->getData());

            $contextProperty = new \ReflectionProperty(Job::class, 'context');
            self::assertSame($context, $contextProperty->getValue($job));
        } finally {
            @unlink($file);
            @rmdir($base . '/app');
            @rmdir($base);
        }
    }
}
