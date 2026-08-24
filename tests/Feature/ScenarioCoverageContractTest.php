<?php

declare(strict_types=1);

use Codegenie\TransactionGuard\Analysis\RuleCatalog;

/** @var array<string, array{code:string,rules:list<string>,absent?:list<string>,config?:array<string,mixed>}> $scenarioMatrix */
$scenarioMatrix = require __DIR__.'/../Support/ScenarioMatrix.php';

it('only references canonical rule ids in scenario expectations', function () use ($scenarioMatrix): void {
    $violations = [];

    foreach ($scenarioMatrix as $name => $case) {
        foreach (array_merge($case['rules'], $case['absent'] ?? []) as $rule) {
            if (! RuleCatalog::exists($rule)) {
                $violations[] = $name.':'.$rule;
            }
        }

        foreach (array_intersect($case['rules'], $case['absent'] ?? []) as $rule) {
            $violations[] = $name.':'.$rule.' is both required and absent';
        }
    }

    expect($violations)->toBe([]);
});

it('requires positive and negative scenarios for every public finding rule', function () use ($scenarioMatrix): void {
    $positive = [];
    $negative = [];

    foreach ($scenarioMatrix as $case) {
        foreach ($case['rules'] as $rule) {
            $positive[$rule] = true;
        }
        foreach ($case['absent'] ?? [] as $rule) {
            $negative[$rule] = true;
        }
    }

    $findingRules = array_values(array_filter(
        RuleCatalog::ids(),
        static fn (string $rule): bool => ! RuleCatalog::isDiagnostic($rule),
    ));
    $missingPositive = array_values(array_filter($findingRules, static fn (string $rule): bool => ! isset($positive[$rule])));
    $missingNegative = array_values(array_filter($findingRules, static fn (string $rule): bool => ! isset($negative[$rule])));

    expect($missingPositive)->toBe([])
        ->and($missingNegative)->toBe([]);
});
