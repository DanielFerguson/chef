<?php

declare(strict_types=1);

use Pest\Contracts\Restarter;
use Pest\Kernel;
use Pest\Panic;
use Pest\Support\Container;
use Pest\TestSuite;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

$applicationRoot = dirname(__DIR__);
$repositoryRoot = dirname($applicationRoot);

if (getenv('CHEF_PEST_TIA_BOOTSTRAPPED') !== '1') {
    putenv('CHEF_PEST_TIA_BOOTSTRAPPED=1');

    $arguments = array_map(
        static fn (string $argument): string => escapeshellarg($argument),
        array_slice($argv, 1),
    );

    $command = implode(' ', [
        escapeshellarg(PHP_BINARY),
        '-d',
        escapeshellarg('pcov.enabled=1'),
        '-d',
        escapeshellarg("pcov.directory={$repositoryRoot}"),
        escapeshellarg(__FILE__),
        ...$arguments,
    ]);

    passthru($command, $status);

    exit($status);
}

$_SERVER['COLLISION_PRINTER'] = 'DefaultPrinter';

require $applicationRoot.'/vendor/autoload.php';

$browserRun = array_any(
    array_slice($_SERVER['argv'], 1),
    static fn (string $argument): bool => str_contains(str_replace('\\', '/', $argument), 'tests/Browser'),
);

if ($browserRun) {
    foreach (['node_modules', 'tests'] as $runtimePath) {
        $link = $repositoryRoot.'/'.$runtimePath;
        $target = $applicationRoot.'/'.$runtimePath;

        if (file_exists($link) || is_link($link) || ! file_exists($target)) {
            continue;
        }

        if (! symlink($target, $link)) {
            throw new RuntimeException("Unable to prepare Pest Browser runtime path [{$link}].");
        }

        register_shutdown_function(static function () use ($link, $target): void {
            if (is_link($link) && readlink($link) === $target) {
                unlink($link);
            }
        });
    }
}

$arguments = $originalArguments = $_SERVER['argv'];
$input = new ArgvInput;
$testSuite = TestSuite::getInstance($repositoryRoot, 'web/tests');
$decorated = $input->getParameterOption('--colors', 'always') !== 'never';
$output = new ConsoleOutput(ConsoleOutput::VERBOSITY_NORMAL, $decorated);

try {
    $kernel = Kernel::boot($testSuite, $input, $output);
    $container = Container::getInstance();

    foreach (Kernel::RESTARTERS as $restarterClass) {
        $restarter = $container->get($restarterClass);

        if (! $restarter instanceof Restarter) {
            throw new RuntimeException("Invalid Pest restarter [{$restarterClass}].");
        }

        $restarter->maybeRestart($repositoryRoot, $originalArguments);
    }

    $status = $kernel->handle($originalArguments, $arguments);
    $kernel->terminate();
} catch (Throwable|Error $exception) {
    Panic::with($exception);
}

exit($status);
