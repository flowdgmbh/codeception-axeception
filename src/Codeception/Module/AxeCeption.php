<?php

declare(strict_types=1);

namespace Codeception\Module;

use Codeception\Module;
use Codeception\Step\Meta;
use Codeception\Test\Cest;
use Codeception\TestInterface;

class AxeCeption extends Module
{
    private ?Cest $currentTest = null;
    public function seeNoAccessibilityIssues(array $knownViolations = []): void
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

        try {
            $violations = $this->getViolations();
        } catch (\Exception $e) {
            // Any error from JavaScript execution or return handling -> fail the test.
            $this->fail($e->getMessage());
            return;
        }

        // Counts unexpected deviations to fail once at the end with a summary.
        $failedViolations = 0;

        foreach ($violations as $violation) {
            $failed = false;

            // Violation is not listed as known -> unexpected violation.
            if (!array_key_exists($violation['id'], $knownViolations)) {
                $this->addStep('seeAccessibilityTest' . ucfirst($violation['id']) . 'DoesNotFail', true);
                $failedViolations++;
                continue;
            }

            // Variant A: a total number of errors is expected for this violation.
            if (is_int($knownViolations[$violation['id']])) {
                // Sum all errors for this violation.
                $numberOfErrors = array_sum($violation['errors']);

                // Check if expected violations are equal
                $failed = $numberOfErrors !== $knownViolations[$violation['id']];
                $this->addStep(
                    'seeAccessibilityTest' . ucfirst($violation['id']) . 'Fails' . $knownViolations[$violation['id']] . 'Time(s)',
                    $failed
                );

                // Mark as failed
                if ($failed) {
                    $failedViolations++;
                }

                // Expectation for this rule is fully processed.
                unset($knownViolations[$violation['id']]);
                continue;
            }

            // Variant B: a per-selector expectation exists.
            foreach ($violation['errors'] as $selector => $numberOfErrors ) {
                // Selector missing in the expectation -> unexpected violation.
                if (!array_key_exists($selector, $knownViolations[$violation['id']])) {
                    $this->addStep(
                        'seeAccessibilityTest' . ucfirst($violation['id']) . 'Fails' . $numberOfErrors . 'Time(s)For' . $selector,
                        true
                    );

                    $failedViolations++;
                    continue;
                }

                // Amount differs -> mark as failed.
                if ($numberOfErrors !== $knownViolations[$violation['id']][$selector]) {
                    $failed = true;
                    $failedViolations++;
                }

                // Step documents the expected amount and whether it matched.
                $this->addStep(
                    'seeAccessibilityTest' . ucfirst($violation['id']) . 'Fails' . $knownViolations[$violation['id']][$selector] . 'Time(s)For' . $selector,
                    $failed
                );

                // Expectation for this selector processed.
                unset($knownViolations[$violation['id']][$selector]);
            }

            // Clean up empty rule entries after processing all selectors.
            if (empty($knownViolations[$violation['id']])) {
                unset($knownViolations[$violation['id']]);
            }

            // If anything failed within this rule, count it.
            if ($failed) {
                $failedViolations++;
            }
        }

        // Remaining expectations were not present in the actual results -> also a failure.
        foreach ($knownViolations as $violationKey => $violationCount) {
            if (is_int($violationCount)) {
                $this->addStep(
                    'seeAccessibilityTest' . ucfirst($violationKey) . 'Fails' . $violationCount . 'Time(s)',
                    true
                );

                $failedViolations++;
                continue;
            }

            foreach ($violationCount as $selector => $numberOfErrors) {
                $this->addStep(
                    'seeAccessibilityTest' . ucfirst($violationKey) . 'Fails' . $numberOfErrors . 'Time(s)For' . $selector,
                    true
                );
                $failedViolations++;
            }
        }

        // If any unexpected or missing expected findings exist -> fail the test.
        if ($failedViolations > 0) {
            $this->fail($failedViolations . ' accessibility issues found');
        }
    }

    public function _before(TestInterface $test): void
    {
        if (!$test instanceof Cest) {
            return;
        }
        $this->currentTest = $test;
    }

    private function addStep(string $action, bool $failed = false): void
    {
        $step = new Meta($action);

        if ($failed) {
            $step->setFailed(true);
        }

        $this->currentTest->getScenario()->addStep($step);
    }

    private function getViolations(): array
    {
        /** @var WebDriver $webdriver */
        $webdriver = $this->getModule('WebDriver');

        // Configurable source for axe-core; double quotes are stripped to simplify injection into the script tag.
        $javascript = str_replace('"', '', $this->config['axeJavascript'] ?? 'https://unpkg.com/axe-core/axe.min.js');

        // Execute JavaScript in the browser context:
        // - Append <script src="...axe.min.js"> to <head>
        // - Wait for onload
        // - Run axe.run()
        // - Normalize violations to include 'errors' (selector => count)
        $validations =  $webdriver->executeJS(
            <<<SCRIPT
            return new Promise((resolve, reject) => {
                const script = document.createElement("script");
                script.src = "$javascript";
                script.onload = () => {
                    axe.run()
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
                script.onerror = reject;
                document.head.appendChild(script);
            });
            SCRIPT
        );

        // Validate the structure before returning to PHP.
        if (!is_array($validations)) {
            throw new \Exception('Axe returned invalid data');
        }

        return $validations;
    }
}
