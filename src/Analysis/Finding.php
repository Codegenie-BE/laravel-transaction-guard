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
    ) {}

    public function fingerprint(): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($this->snippet)) ?? trim($this->snippet);

        $file = str_replace('\\', '/', $this->file);
        $cwd = getcwd();
        if (is_string($cwd) && $cwd !== '') {
            $cwd = rtrim(str_replace('\\', '/', realpath($cwd) ?: $cwd), '/').'/';
            if (str_starts_with($file, $cwd)) {
                $file = substr($file, strlen($cwd));
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
            'snippet' => $this->snippet,
            'remediation' => $this->remediation,
            'confidence' => $this->confidence,
            'context' => $this->context,
            'fingerprint' => $this->fingerprint(),
        ];
    }
}
