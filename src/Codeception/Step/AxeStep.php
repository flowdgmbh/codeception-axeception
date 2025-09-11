<?php

declare(strict_types=1);

namespace Codeception\Step;

class AxeStep extends Meta
{
    protected array $axeResult = [];
    protected string $url = '';
    protected string $reportPath = '';

    public function getAxeResult(): array
    {
        return $this->axeResult;
    }
    public function setAxeResult(array $result): void
    {
        $this->axeResult = $result;
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setReportPath(string $reportPath): void
    {
        $this->reportPath = $reportPath;
    }

    public function getReportPath(): string
    {
        return $this->reportPath;
    }
}
