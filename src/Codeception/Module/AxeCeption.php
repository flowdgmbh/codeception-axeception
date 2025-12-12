<?php

declare(strict_types=1);

namespace Codeception\Module;

use Codeception\Module;
use Codeception\Step\AxeStep;
use Codeception\Test\Cest;
use Codeception\TestInterface;

/**
 * Codeception module integrating axe-core accessibility checks.
 *
 * Responsibilities:
 * - Ensure WebDriver is available (axe requires a real browser context).
 * - Inject axe-core into the current page, optionally applying user configuration via axe.configure.
 * - Execute axe.run() and collect violations.
 * - Record each violation as a custom AxeStep so our Twig reporter can render a detailed report.
 */
class AxeCeption extends Module
{
    /**
     * Holds the current Cest instance while the scenario is running.
     * It is provided by Codeception at runtime; used to add custom AxeStep entries.
     */
    private ?Cest $currentTest = null;

    /**
     * Codeception lifecycle hook: called before each test.
     * Store current Cest instance so we can attach AxeStep entries to the scenario.
     */
    public function _before(TestInterface $test): void
    {
        $this->currentTest = $test instanceof Cest ? $test : null;
    }

    /**
     * Codeception lifecycle hook: called after each test.
     * Clear the reference to avoid leaking across tests.
     */
    public function _after(TestInterface $test): void
    {
        $this->currentTest = null;
    }

    /**
     * Runs axe-core in the current browser context and fails the test if violations are found.
     *
     * Preconditions:
     * - WebDriver module must be enabled; otherwise the test is skipped.
     * - Method is intended to be used inside a Cest scenario.
     * 
     * @param array<mixed> $axeRunConfiguration Axe-Core.run() Configuration options
     * @param array<mixed> $axeConfiguration Axe-Core Configuration options
     */
    public function seeNoAccessibilityIssues(array $axeRunConfiguration = [], array $axeConfiguration = []): void
    {
        // Without WebDriver (e.g., PhpBrowser mode) we cannot run axe.
        if (!$this->hasModule('WebDriver')) {
            $this->markTestSkipped('WebDriver module not loaded');
            return;
        }

        // Only proceed when running inside a Cest scenario.
        if (!$this->currentTest instanceof Cest) {
            return;
        }

        /** @var WebDriver $webDriver */
        $webDriver = $this->getModule('WebDriver');
        assert($webDriver instanceof WebDriver, 'WebDriver module not loaded');

        try {
            $violations = $this->getViolations($webDriver, $axeRunConfiguration, $axeConfiguration);
        } catch (\Exception $e) {
            // Any error from JavaScript execution or return handling -> fail the test.
            $this->fail($e->getMessage());
            return;
        }

        // Record each violation as a separate AxeStep for reporting.
        foreach ($violations as $violation) {
            $this->addStep(
                'seeAccessibilityTest' . ucfirst($violation['id']) . 'DoesNotFail',
                $violation,
                $webDriver->webDriver->getCurrentURL(),
                true
            );
        }

        // Fail the test if any violations were found.
        if (count($violations) > 0) {
            $this->fail(count($violations) . ' accessibility issues found');
        }
    }

    /**
     * Adds a custom AxeStep to the current scenario so the reporter can render it later.
     */
    private function addStep(string $action, array $violation, string $testedPageUrl, bool $failed = false): void
    {
        $step = new AxeStep($action);

        if ($failed) {
            $step->setFailed(true);
        }

        $step->setAxeResult($violation);
        $step->setUrl($testedPageUrl);
        $step->setReportPath($this->config['reportFilename'] ?? '');

        // currentTest is guaranteed by seeNoAccessibilityIssues precondition.
        $this->currentTest?->getScenario()->addStep($step);
    }

    /**
     * Injects axe-core, applies optional configuration, runs axe, and returns violations.
     *
     * @param WebDriver $webDriver Codeception WebDriver module instance
     * @param array<mixed> $axeRunConfiguration Axe-Core.run() Configuration options
     * @param array<mixed> $axeConfiguration Axe-Core Configuration options
     * @return array<int, array<string, mixed>> List of axe violations as arrays (JSON from the browser)
     * @throws \Exception when axe could not be executed or returned invalid data
     */
    private function getViolations(WebDriver $webDriver, array $axeRunConfiguration, array $axeConfiguration): array
    {
        // Configurable source for axe-core; double quotes are stripped to simplify injection into the script tag.
        // You can override this via module config: axeJavascript: "https://.../axe.min.js"
        $javascript = str_replace('"', '', $this->config['axeJavascript'] ?? 'https://unpkg.com/axe-core/axe.min.js');

        // Optional: pass axe.configure(...) options from module config (axeConfigure: { rules: { ... } })
        $axeConfiguration = $this->arrayMergeRecursiveOverwrite($this->config['axeConfigure'] ?? [], $axeConfiguration);
        $configureJs = '';
        if (is_array($axeConfiguration)) {
            $json = json_encode($axeConfiguration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            // This line will be interpolated into JS below right before axe.run()
            $configureJs = "axe.configure($json);";
        }

        $runConfigJavaScriptObject = '';
        if (is_array($axeRunConfiguration)) {
            // This line will be interpolated into JS below right before axe.run()
            $runConfigJavaScriptObject = json_encode($axeRunConfiguration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $violations =  $webDriver->executeJS(
            /** @lang JavaScript */ <<<SCRIPT
            return new Promise((resolve, reject) => {
                const script = document.createElement("script");
                script.onload = () => {
                    $configureJs
                    axe.run($runConfigJavaScriptObject)
                        .then(results => {
                            if (results.violations.length) {
                                results.violations.forEach((violation) => {
                                    violation.errors = {};
                                    violation.nodes.forEach((node) => {
                                        node.target.forEach((target) => {
                                            violation.errors[target] = document.querySelectorAll(target).length;
                                        });
                                    });
                                });
                                resolve(results.violations);
                            } else {
                                resolve([]);
                            }
                        })
                        .catch(reject);
                };
                script.src = "$javascript";
                script.onerror = () => reject('Could not load axe-core script');
                document.head.appendChild(script);
            }).catch(e => e);
            SCRIPT
        );

        // Validate the structure before returning to PHP.
        if (!is_array($violations)) {
            throw new \Exception('Axe returned invalid data: ' . $violations);
        }

        return $violations;
    }

    private function arrayMergeRecursiveOverwrite(array $array1, array $array2): array
    {
        foreach ($array2 as $key => $value) {

            // Key entfernen wenn "__unset__"
            if ($value === "__unset__") {
                unset($array1[$key]);
                continue;
            }

            // Rekursiv mergen, wenn beide Arrays sind
            if (array_key_exists($key, $array1) && is_array($value) && is_array($array1[$key])) {
                $array1[$key] = array_merge_recursive_overwrite($array1[$key], $value);
            } else {
                $array1[$key] = $value;
            }
        }

        return $array1;
    }
}
