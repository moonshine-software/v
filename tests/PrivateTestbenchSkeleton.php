<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Keeps Testbench on the per-process skeleton created by tests/bootstrap.php.
 * Testbench removes TESTBENCH_APP_BASE_PATH after every test class.
 */
final class PrivateTestbenchSkeleton implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $path = $_SERVER['TESTBENCH_APP_BASE_PATH'] ?? null;
        if (! is_string($path) || $path === '') {
            return;
        }

        $facade->registerSubscriber(new readonly class($path) implements PreparationStartedSubscriber
        {
            public function __construct(private string $path) {}

            public function notify(PreparationStarted $event): void
            {
                $_ENV['TESTBENCH_APP_BASE_PATH'] = $this->path;
            }
        });
    }
}
