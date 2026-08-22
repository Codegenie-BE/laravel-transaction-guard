<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final class StaticExpressionResolver
{
    public static function booleanFirstArgument(string $statement, string $method): ?bool
    {
        if (preg_match('/(?:->|::)\s*'.preg_quote($method, '/').'\s*\(\s*(true|false)\b/i', $statement, $match) !== 1) {
            return null;
        }

        return strtolower($match[1]) === 'true';
    }

    public static function firstStringArgument(string $call): ?string
    {
        $tokens = token_get_all('<?php '.$call);

        $inside = false;
        $parts = [];
        $expectValue = true;
        $heredoc = null;

        foreach ($tokens as $token) {
            if (! $inside) {
                if ($token === '(') {
                    $inside = true;
                }

                continue;
            }

            if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if ($heredoc !== null) {
                if (is_array($token) && $token[0] === T_END_HEREDOC) {
                    $parts[] = $heredoc;
                    $heredoc = null;
                    $expectValue = false;

                    continue;
                }
                if (is_array($token) && $token[0] === T_ENCAPSED_AND_WHITESPACE) {
                    $heredoc .= $token[1];

                    continue;
                }

                return null;
            }

            if ($token === ')' || $token === ',') {
                break;
            }
            if ($token === '.') {
                if ($expectValue) {
                    return null;
                }
                $expectValue = true;

                continue;
            }
            if (! $expectValue) {
                return null;
            }
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literal = $token[1];
                if (strlen($literal) < 2) {
                    return null;
                }
                $parts[] = stripcslashes(substr($literal, 1, -1));
                $expectValue = false;

                continue;
            }
            if (is_array($token) && $token[0] === T_START_HEREDOC) {
                $heredoc = '';

                continue;
            }

            return null;
        }

        return $parts === [] || $expectValue ? null : implode('', $parts);
    }
}
