<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final class RedisOperationClassifier
{
    /** @var list<string> */
    private const GETEX_MUTATING_MODIFIERS = ['EX', 'PX', 'EXAT', 'PXAT', 'PERSIST'];

    /**
     * @return array{bool,bool} mutates, unknown
     */
    public static function getexMutationState(string $source): array
    {
        $found = false;
        $unknown = false;
        $offset = 0;

        while (preg_match('/\bgetex\s*\(/i', $source, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $found = true;
            $matched = $match[0][0];
            $matchOffset = $match[0][1];
            $open = $matchOffset + strlen($matched) - 1;
            $call = self::delimitedContent($source, $open, '(', ')');
            if ($call === null) {
                $unknown = true;
                $offset = $open + 1;

                continue;
            }

            [$arguments, $close] = $call;
            [$mutates, $callUnknown] = self::getexArgumentsMutationState(self::splitTopLevelArguments($arguments));
            if ($mutates) {
                return [true, false];
            }
            $unknown = $unknown || $callUnknown;
            $offset = $close + 1;
        }

        $offset = 0;
        while (preg_match('/\bcommand\s*\(/i', $source, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $matched = $match[0][0];
            $matchOffset = $match[0][1];
            $open = $matchOffset + strlen($matched) - 1;
            $call = self::delimitedContent($source, $open, '(', ')');
            if ($call === null) {
                $offset = $open + 1;

                continue;
            }

            [$arguments, $close] = $call;
            $parts = self::splitTopLevelArguments($arguments);
            $command = isset($parts[0]) ? self::literalString($parts[0]) : null;
            if ($command === null || strcasecmp($command, 'GETEX') !== 0) {
                $offset = $close + 1;

                continue;
            }

            $found = true;
            if (! isset($parts[1])) {
                $unknown = true;
                $offset = $close + 1;

                continue;
            }

            $array = trim($parts[1]);
            if (! str_starts_with($array, '[')) {
                $unknown = true;
                $offset = $close + 1;

                continue;
            }

            $arrayCall = self::delimitedContent($array, 0, '[', ']');
            if ($arrayCall === null || trim(substr($array, $arrayCall[1] + 1)) !== '') {
                $unknown = true;
                $offset = $close + 1;

                continue;
            }

            [$arrayArguments] = $arrayCall;
            [$mutates, $callUnknown] = self::getexArgumentsMutationState(self::splitTopLevelArguments($arrayArguments));
            if ($mutates) {
                return [true, false];
            }
            $unknown = $unknown || $callUnknown;
            $offset = $close + 1;
        }

        return $found ? [false, $unknown] : [false, true];
    }

    /**
     * @param  list<string>  $arguments
     * @return array{bool,bool} mutates, unknown
     */
    private static function getexArgumentsMutationState(array $arguments): array
    {
        if (count($arguments) <= 1) {
            return [false, false];
        }

        $modifierExpression = preg_replace('/^\s*(?:modifier|options)\s*:\s*/i', '', $arguments[1]) ?? $arguments[1];
        $modifierExpression = trim($modifierExpression);

        if (str_starts_with($modifierExpression, '[')) {
            return self::getexOptionsArrayMutationState($modifierExpression);
        }

        $modifier = self::literalString($modifierExpression);
        if ($modifier === null) {
            return [false, true];
        }

        $modifier = strtoupper($modifier);
        if ($modifier === '') {
            return [false, false];
        }
        if (in_array($modifier, self::GETEX_MUTATING_MODIFIERS, true)) {
            return [true, false];
        }

        return [false, true];
    }

    /** @return array{bool,bool} mutates, unknown */
    private static function getexOptionsArrayMutationState(string $expression): array
    {
        $expression = trim($expression);
        $array = self::delimitedContent($expression, 0, '[', ']');
        if ($array === null || trim(substr($expression, $array[1] + 1)) !== '') {
            return [false, true];
        }

        [$content] = $array;
        $entries = self::splitTopLevelArguments($content);
        if ($entries === []) {
            return [false, false];
        }

        $unknown = false;
        foreach ($entries as $entry) {
            if (preg_match('/^(?<key>.+?)\s*=>\s*(?<value>.+)$/s', $entry, $pair) === 1) {
                $key = self::literalString(trim($pair['key']));
                if ($key === null) {
                    $unknown = true;

                    continue;
                }
                if (in_array(strtoupper($key), self::GETEX_MUTATING_MODIFIERS, true)) {
                    return [true, false];
                }

                $unknown = true;

                continue;
            }

            $value = self::literalString($entry);
            if ($value !== null && in_array(strtoupper($value), self::GETEX_MUTATING_MODIFIERS, true)) {
                return [true, false];
            }

            $unknown = true;
        }

        return [false, $unknown];
    }

    /** @return array{string,int}|null */
    private static function delimitedContent(string $source, int $open, string $openCharacter, string $closeCharacter): ?array
    {
        if (($source[$open] ?? null) !== $openCharacter) {
            return null;
        }

        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($source);

        for ($i = $open; $i < $length; $i++) {
            $character = $source[$i];
            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;

                    continue;
                }
                if ($character === '\\') {
                    $escaped = true;

                    continue;
                }
                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '\'' || $character === '"') {
                $quote = $character;

                continue;
            }
            if ($character === $openCharacter) {
                $depth++;

                continue;
            }
            if ($character === $closeCharacter) {
                $depth--;
                if ($depth === 0) {
                    return [substr($source, $open + 1, $i - $open - 1), $i];
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function splitTopLevelArguments(string $source): array
    {
        if (trim($source) === '') {
            return [];
        }

        $parts = [];
        $start = 0;
        $paren = $bracket = $brace = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($source);

        for ($i = 0; $i < $length; $i++) {
            $character = $source[$i];
            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;

                    continue;
                }
                if ($character === '\\') {
                    $escaped = true;

                    continue;
                }
                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '\'' || $character === '"') {
                $quote = $character;

                continue;
            }
            if ($character === '(') {
                $paren++;
            } elseif ($character === ')') {
                $paren--;
            } elseif ($character === '[') {
                $bracket++;
            } elseif ($character === ']') {
                $bracket--;
            } elseif ($character === '{') {
                $brace++;
            } elseif ($character === '}') {
                $brace--;
            } elseif ($character === ',' && $paren === 0 && $bracket === 0 && $brace === 0) {
                $parts[] = trim(substr($source, $start, $i - $start));
                $start = $i + 1;
            }
        }

        $parts[] = trim(substr($source, $start));

        return $parts;
    }

    private static function literalString(string $expression): ?string
    {
        $expression = trim($expression);
        if (preg_match('/^([\'\"])(.*)\1$/s', $expression, $match) !== 1) {
            return null;
        }

        return stripcslashes($match[2]);
    }
}
