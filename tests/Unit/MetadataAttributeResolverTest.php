<?php

declare(strict_types=1);

use Codegenie\TransactionGuard\Analysis\FileContext;
use Codegenie\TransactionGuard\Analysis\MetadataAttributeResolver;

it('does not treat an unimported local short attribute as Laravel DebounceFor', function (): void {
    $source = "<?php\nnamespace App\\Jobs;\n#[DebounceFor(5)]\nclass Job {}\n";
    $offset = strpos($source, 'class Job');
    expect($offset)->not->toBeFalse();

    $context = new FileContext('App\\Jobs', []);

    expect(MetadataAttributeResolver::hasClassAttribute(
        $source,
        $offset,
        $context,
        'Illuminate\\Queue\\Attributes\\DebounceFor',
        'DebounceFor',
    ))->toBeFalse();
});

it('accepts an imported Laravel DebounceFor short attribute', function (): void {
    $source = "<?php\nnamespace App\\Jobs;\n#[DebounceFor(5)]\nclass Job {}\n";
    $offset = strpos($source, 'class Job');
    expect($offset)->not->toBeFalse();

    $context = new FileContext('App\\Jobs', [
        'DebounceFor' => 'Illuminate\\Queue\\Attributes\\DebounceFor',
    ]);

    expect(MetadataAttributeResolver::hasClassAttribute(
        $source,
        $offset,
        $context,
        'Illuminate\\Queue\\Attributes\\DebounceFor',
        'DebounceFor',
    ))->toBeTrue();
});

it('accepts the fully qualified Laravel attribute without an import', function (): void {
    $source = "<?php\nnamespace App\\Jobs;\n#[\\Illuminate\\Queue\\Attributes\\DebounceFor(5)]\nclass Job {}\n";
    $offset = strpos($source, 'class Job');
    expect($offset)->not->toBeFalse();

    $context = new FileContext('App\\Jobs', []);

    expect(MetadataAttributeResolver::hasClassAttribute(
        $source,
        $offset,
        $context,
        'Illuminate\\Queue\\Attributes\\DebounceFor',
        'DebounceFor',
    ))->toBeTrue();
});
