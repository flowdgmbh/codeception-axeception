# axeception

A Codeception module for automated accessibility testing using axe-core.

## Overview

This package provides a Codeception module that integrates with axe-core to perform accessibility testing on web pages. It allows you to automatically check your web pages for accessibility issues during your acceptance tests.

## Installation

Install the package via Composer:

```bash
composer require flowd/axeception
```

## Requirements

- PHP 8.3 or higher
- Codeception
- WebDriver module for Codeception

## Configuration

Add the module to your Codeception suite configuration file (e.g., `acceptance.suite.yml`):

```yaml
modules:
    enabled:
        - WebDriver:
            # WebDriver configuration...
        - AxeCeption:
            # Optional: specify a custom URL for axe.js
            axeJavascript: '/axe.min.js'
```

By default, the module uses the axe-core library from `https://unpkg.com/axe-core/axe.min.js`. You can specify a different URL or a local path if needed.

## Usage

### Basic Usage

In your Codeception test, use the `seeNoAccessibilityIssues()` method to check for accessibility issues:

```php
<?php
// In your Cest file
public function testAccessibility(AcceptanceTester $I)
{
    $I->amOnPage('/your-page');
    $I->seeNoAccessibilityIssues([]);
}
```

### Handling Known Violations

You can declare known (expected) violations so the test only fails when the actual count deviates from what you expect. There are two modes:

- Per rule (single integer): expect a total number of errors for a rule.
- Per selector (map): expect an exact number per CSS selector for a rule.

Do not mix both modes for the same rule.

```php
<?php
public function testAccessibility(AcceptanceTester $I)
{
    $I->amOnPage('/your-page');
    $I->seeNoAccessibilityIssues([
        'aria-allowed-role' => [
            '.b-dot-seperated-list' => 1,
            '.footer'               => 2,
        ],
        'label'             => 1,
    ]);
}
```

The test will only fail if the number of violations for each rule doesn't match the expected count.

## How It Works

1. The module injects the axe-core JavaScript library into the page
2. It runs axe.run() to analyze the page for accessibility issues
3. It counts the number of violations for each rule
4. It compares the actual violations with the expected violations
5. It adds test steps to the Codeception output for each violation
6. If there are unexpected violations, the test fails

## Example

Here's a complete example of how to use the module in a Codeception test:

```php
<?php
namespace Tests\Acceptance;

use Codeception\Example;
use Tests\Support\FrontendUser;

class AccessibilityCest
{
    /**
     * Test homepage accessibility
     */
    public function homepageAccessibility(FrontendUser $I)
    {
        $I->amOnPage('/');
        $I->seeNoAccessibilityIssues([
            // Known issues that we're working on
            'aria-allowed-role' => [
                '.b-dot-seperated-list' => 1,
                '.footer'               => 2,
            ],
            'label'             => 1,
            ]);
    }
}
```

## Troubleshooting

### WebDriver Module Not Loaded

If you see the error "WebDriver module not loaded", make sure:

1. The WebDriver module is enabled in your suite configuration
2. The WebDriver module is listed before the AxeCeption module

### Invalid Data Error

If you see "Axe returned invalid data", check:

1. The page is fully loaded before running the accessibility test
2. There are no JavaScript errors on the page
3. The axe-core library URL is accessible
