<?php

declare(strict_types=1);

$mutationRunnerPath = dirname(__DIR__).'/vendor/pestphp/pest-plugin-mutate/src/Tester/MutationTestRunner.php';
$expectedOriginalChecksum = '4773491d668f1abae1136c4ab1e6cdf221d1e53286cb72d3f33f5a594bf21b89';

$source = file_get_contents($mutationRunnerPath);
if ($source === false) {
    throw new RuntimeException("Unable to read Pest's mutation runner at [{$mutationRunnerPath}].");
}

$original = <<<'PHP'
        /** @var CodeCoverage $codeCoverage */
        $codeCoverage = require $reportPath;

        unlink($reportPath);
        $coveredLines = array_map(fn (array $lines): array => array_filter($lines, fn (?array $tests): bool => $tests !== [] && $tests !== null), $codeCoverage->getData()->lineCoverage());
PHP;

$previousReplacement = <<<'PHP'
        /** @var CodeCoverage|array{codeCoverage?: mixed} $codeCoverage */
        $codeCoverage = require $reportPath;

        unlink($reportPath);
        $coverageData = is_array($codeCoverage)
            ? ($codeCoverage['codeCoverage'] ?? null)
            : $codeCoverage->getData();

        if (! $coverageData instanceof \SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData) {
            Container::getInstance()->get(Printer::class)->reportError('Unsupported coverage report format, aborting mutation testing.');

            return 1;
        }

        $coveredLines = array_map(fn (array $lines): array => array_filter($lines, fn (?array $tests): bool => $tests !== [] && $tests !== null), $coverageData->lineCoverage());
PHP;

$replacement = <<<'PHP'
        /** @var CodeCoverage|array{basePath?: mixed, codeCoverage?: mixed} $codeCoverage */
        $codeCoverage = require $reportPath;

        unlink($reportPath);
        $coverageData = is_array($codeCoverage)
            ? ($codeCoverage['codeCoverage'] ?? null)
            : $codeCoverage->getData();

        if (! $coverageData instanceof \SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData) {
            Container::getInstance()->get(Printer::class)->reportError('Unsupported coverage report format, aborting mutation testing.');

            return 1;
        }

        $coveredLines = array_map(fn (array $lines): array => array_filter($lines, fn (?array $tests): bool => $tests !== [] && $tests !== null), $coverageData->lineCoverage());

        $coverageBasePath = is_array($codeCoverage) ? ($codeCoverage['basePath'] ?? null) : null;
        if (is_string($coverageBasePath) && $coverageBasePath !== '') {
            $absoluteCoveredLines = [];
            foreach ($coveredLines as $file => $lines) {
                $absolutePath = str_starts_with($file, DIRECTORY_SEPARATOR)
                    ? $file
                    : $coverageBasePath.DIRECTORY_SEPARATOR.$file;
                $absoluteCoveredLines[$absolutePath] = $lines;
            }
            $coveredLines = $absoluteCoveredLines;
        }
PHP;

if (str_contains($source, $replacement)) {
    exit(0);
}

if (substr_count($source, $previousReplacement) === 1) {
    $patchedSource = str_replace($previousReplacement, $replacement, $source);
    if (file_put_contents($mutationRunnerPath, $patchedSource) === false) {
        throw new RuntimeException("Unable to update Pest's mutation runner at [{$mutationRunnerPath}].");
    }

    exit(0);
}

$actualChecksum = hash('sha256', $source);
if (! hash_equals($expectedOriginalChecksum, $actualChecksum)) {
    throw new RuntimeException(
        "Pest's mutation runner checksum changed ({$actualChecksum}); review and remove or update the PHPUnit 13 coverage compatibility patch.",
    );
}

if (substr_count($source, $original) !== 1) {
    throw new RuntimeException("Pest's mutation runner no longer contains the expected coverage-loading block.");
}

$patchedSource = str_replace($original, $replacement, $source);
if (file_put_contents($mutationRunnerPath, $patchedSource) === false) {
    throw new RuntimeException("Unable to prepare Pest's mutation runner at [{$mutationRunnerPath}].");
}
