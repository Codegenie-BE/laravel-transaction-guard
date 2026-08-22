<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final readonly class Finding
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        public string $rule,
        public Severity $severity,
        public string $message,
        public string $file,
        public int $line,
        public string $snippet,
        public string $remediation,
        public string $confidence = 'high',
        public array $context = [],
        public ?int $column = null,
        public ?int $endColumn = null,
        public string $projectRoot = '',
    ) {}

    public function fingerprint(): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($this->snippet)) ?? trim($this->snippet);

        $file = str_replace('\\', '/', $this->file);
        $root = $this->projectRoot;
        if ($root !== '') {
            $root = rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/').'/';
            if (str_starts_with($file, $root)) {
                $file = substr($file, strlen($root));
            }
        }

        return hash('sha256', implode('|', [
            $this->rule,
            $file,
            $normalized,
        ]));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'severity' => $this->severity->label(),
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'column' => $this->column,
            'end_column' => $this->endColumn,
            'snippet' => $this->snippet,
            'remediation' => $this->remediation,
            'confidence' => $this->confidence,
            'context' => $this->context,
            'fingerprint' => $this->fingerprint(),
        ];
    }
}
