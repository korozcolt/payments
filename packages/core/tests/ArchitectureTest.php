<?php

declare(strict_types=1);

/*
 * The whole point of payments-core: it must never depend on a framework.
 *
 * Token-based so comments/docblocks that merely mention Laravel are ignored.
 */
function frameworkCouplingIn(string $code): array
{
    $banned = ['config', 'now', 'event', 'app', 'collect', 'data_get', 'data_set', 'optional', 'tap', 'route', 'url', 'env', 'retry', 'blank', 'filled', 'dispatch'];
    $tokens = array_values(array_filter(
        token_get_all($code),
        fn ($t) => ! (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)),
    ));

    $found = [];

    foreach ($tokens as $i => $token) {
        if (! is_array($token)) {
            continue;
        }

        [$id, $text] = $token;

        // `use Illuminate\...` or an inline FQCN.
        if (in_array($id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && str_contains($text, 'Illuminate\\')) {
            $found[] = $text;
        }

        // Global helper call, e.g. config('x') — not ->now(), ::now() or `function now(`.
        if ($id === T_STRING && in_array($text, $banned, true) && ($tokens[$i + 1] ?? null) === '(') {
            $prev = $tokens[$i - 1] ?? null;
            $isMemberOrDecl = is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true);

            if (! $isMemberOrDecl) {
                $found[] = $text.'()';
            }
        }
    }

    return $found;
}

it('detects framework coupling (sanity check of the rule itself)', function () {
    expect(frameworkCouplingIn('<?php use Illuminate\Support\Str; $a = config("x");'))->toHaveCount(2)
        ->and(frameworkCouplingIn('<?php /** Illuminate\Http config() */ $c->now(); function now() {}'))->toBe([]);
});

it('has no Illuminate (Laravel) dependency anywhere in src', function () {
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../src'));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $hits = frameworkCouplingIn((string) file_get_contents($file->getPathname()));

        if ($hits !== []) {
            $offenders[$file->getPathname()] = $hits;
        }
    }

    expect($offenders)->toBe([]);
});
