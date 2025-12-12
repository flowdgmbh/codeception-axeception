<?php

declare(strict_types=1);

namespace Codeception\Extension;

use Codeception\Event\PrintResultEvent;
use Codeception\Event\SuiteEvent;
use Codeception\Events;
use Codeception\Extension;
use Codeception\Module\AxeCeption;
use Codeception\Step\AxeStep;
use Codeception\Subscriber\Shared\StaticEventsTrait;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * AxeCeptionReporter
 *
 * Responsibilities:
 * - Collect AxeStep results from all tests of a suite (suiteAfter)
 * - Build a clean view-model for Twig at the end of the run (resultPrintAfter)
 * - Render a single consolidated HTML report using templates in template/
 *
 * Notes:
 * - This reporter does not influence test results; it only reads what the AxeCeption module
 *   recorded as AxeStep entries in scenarios.
 * - The output file defaults to tests/_output/axeception-report.html, but an AxeStep may override
 *   the report name via its reportPath (configured in the Yaml-File).
 */
class AxeCeptionReporter extends Extension
{
    use StaticEventsTrait;

    /**
     * Codeception event hooks used by this reporter.
     * @var array<string, string>
     */
    protected static array $events = [
        Events::RESULT_PRINT_AFTER => 'resultPrintAfter', // render report when results are printed
        Events::SUITE_AFTER => 'suiteAfter',              // collect Axe steps after each suite
    ];

    /** @var string Absolute path to the Twig templates directory */
    protected string $templatePath;

    /** @var string Output report path (absolute or relative to _output/) */
    private string $reportFile;

    /**
     * @var array<int, array{test: mixed, results: AxeStep[]}> Aggregated Axe steps grouped per test
     */
    private array $axeResults = [];

    /** @var Environment Twig environment for rendering the templates */
    private Environment $twig;

    public function __construct(array $options, array $output)
    {
        // Default report name; may be overridden by AxeStep->getReportPath()
        $this->reportFile = 'axeception-report.html';

        // Resolve templates directory inside this package
        $this->templatePath = sprintf(
            '%s%stemplate%s',
            __DIR__,
            DIRECTORY_SEPARATOR,
            DIRECTORY_SEPARATOR
        );

        // Prepare Twig
        $loader = new FilesystemLoader($this->templatePath);
        $this->twig = new Environment($loader, [
            'cache' => false,          // can be switched to a folder path if desired
            'autoescape' => 'html',    // safe defaults
        ]);

        parent::__construct($options, $output);
    }

    /**
     * Render the final report after Codeception prints results.
     *
     * - Transforms aggregated AxeSteps into a per-test view-model for Twig
     * - Derives anchors for a table of contents
     * - Resolves output path and writes the HTML file
     */
    public function resultPrintAfter(PrintResultEvent $printResultEvent): void
    {
        $testsVm = [];
        $testIndex = 0;

        foreach ($this->axeResults as $axeResult) {
            $test = $axeResult['test'];
            /** @var AxeStep[] $axeSteps */
            $axeSteps = $axeResult['results'];

            $stepIndex = 1;
            $violations = [];
            $url = null; // last seen URL for this test (taken from steps)

            foreach ($axeSteps as $axeStep) {
                $axe = $axeStep->getAxeResult();
                $url = $axeStep->getUrl();

                // Map each node to the template structure
                $nodesVm = [];
                $nodeIndex = 1;
                foreach ($axe['nodes'] as $node) {
                    $failureSummary = explode("\n", (string)($node['failureSummary'] ?? ''));
                    $highlight = array_shift($failureSummary) ?? '';

                    $nodesVm[] = [
                        'index' => $nodeIndex,
                        'targetNodes' => implode(', ', array_map('strval', (array)($node['target'] ?? []))),
                        'html' => (string)($node['html'] ?? ''),
                        'fixSummaries' => [[
                            'highlight' => $highlight,
                            'list' => $failureSummary,
                        ]],
                    ];

                    $nodeIndex++;
                }

                $wcag = $this->getWCAGfromTags($axe['tags'] ?? []);

                // Single entry per axe violation (aka step)
                $violations[] = [
                    'index' => $stepIndex,
                    'help' => (string)($axe['help'] ?? ''),
                    'helpUrl' => (string)($axe['helpUrl'] ?? ''),
                    'id' => (string)($axe['id'] ?? ''),
                    'wcag' => $wcag,
                    'description' => (string)($axe['description'] ?? ''),
                    'impact' => (string)($axe['impact'] ?? ''),
                    'tags' => array_map('strval', (array)($axe['tags'] ?? [])),
                    'nodes' => $nodesVm,
                ];

                // If a step provides a custom report path, resolve it (absolute or _output-relative)
                if ($axeStep->getReportPath() !== null) {
                    $this->reportFile = $axeStep->getReportPath();
                    if (!codecept_is_path_absolute($this->reportFile)) {
                        $this->reportFile = codecept_output_dir($this->reportFile);
                    }
                }

                $stepIndex++;
            }

            // Optional: include the failure message of this test (if any)
            $failures = $printResultEvent->getResult()->failures();

            $testName = method_exists($test, 'getName') ? $test->getName() : (string)$test;
            $baseId = preg_replace('~[^a-z0-9]+~i', '-', strtolower($testName)) ?: 'test-' . ($testIndex + 1);
            $anchorId = rtrim($baseId, '-');

            $testsVm[] = [
                'name' => $testName,
                'anchorId' => $anchorId,         // used by page.twig for TOC and by test.twig as target
                'url' => $url,                   // last page URL involved in this test
                'failMessage' => isset($failures[$testIndex]) ? $failures[$testIndex]->getFail()->getMessage() : null,
                'violationsSummary' => sprintf('Found %d violations', count($violations)),
                'violations' => $violations,
            ];

            $testIndex++;
        }

        // Render and write the final HTML
        $html = $this->twig->render('page.twig', [
            'tests' => $testsVm,
        ]);

        file_put_contents($this->reportFile, $html);
    }

    /**
     * Aggregates AxeStep entries from all tests in the suite. We only keep tests that actually
     * contain AxeStep instances.
     */
    public function suiteAfter(SuiteEvent $suiteEvent): void
    {
        if ($this->getAxeCeptionModuleFromSuiteEvent($suiteEvent)) {
            foreach ($suiteEvent->getSuite()->getTests() as $test) {
                $steps = $test->getScenario()->getSteps();
                $results = [];
                foreach ($steps as $step) {
                    if ($step instanceof AxeStep) {
                        $results[] = $step;
                    }
                }
                if (count($results) > 0) {
                    $this->axeResults[] = [
                        'test' => $test,
                        'results' => $results,
                    ];
                }
            }
        }
    }

    /**
     * Helper: ensure the AxeCeption module is present in the running suite.
     */
    private function getAxeCeptionModuleFromSuiteEvent(SuiteEvent $suiteEvent): ?AxeCeption
    {
        $modules = $suiteEvent->getSuite()->getModules();
        foreach ($modules as $module) {
            if ($module instanceof AxeCeption) {
                return $module;
            }
        }
        return null;
    }

    /**
     * Helper: convert axe rule tags into a comma-separated string (e.g., WCAG references).
     *
     * @param array<int, string> $tags
     */
    private function getWCAGfromTags(array $tags): string
    {
        $wcag = '';
        foreach ($tags as $tag) {
            $wcag .= $tag . ', ';
        }
        return rtrim($wcag, ', ');
    }
}
