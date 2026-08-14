<?php

/**
 * Syntax gate for the JavaScript that lives inside PHP heredocs.
 *
 * Two thirds of the plugin's JS is emitted from `<script>` blocks in heredocs,
 * where `php -l` only sees a string. One forgotten `\$` turns a jQuery call
 * into an interpolated PHP variable, which breaks the whole block — filters,
 * modals and drag & drop die silently on that page.
 *
 * Rule 1 (hard): inside a heredoc script, PHP values are written as `{$var}`
 *                and jQuery as `\$`. A bare `$var` is therefore always a bug.
 * Rule 2:        the block must parse as JavaScript (`node --check`), with
 *                `{$var}` swapped for a placeholder identifier.
 */

$root  = dirname(__DIR__);
$dirs  = ['src', 'ajax', 'front'];
$node  = trim((string)@shell_exec('command -v node 2>/dev/null'));
$tmp   = sys_get_temp_dir() . '/sprint-inline-js-' . getmypid();
$blocks = $failures = 0;

foreach ($dirs as $dir) {
    foreach (glob($root . '/' . $dir . '/*.php') as $file) {
        $code = (string)file_get_contents($file);
        if (!preg_match_all('/<<<(\w+)\R(.*?)\R\1;/s', $code, $heredocs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($heredocs as $heredoc) {
            $body   = $heredoc[2][0];
            $offset = $heredoc[2][1];
            if (!preg_match_all('#<script>(.*?)</script>#s', $body, $scripts, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($scripts as $script) {
                $blocks++;
                $js   = $script[1][0];
                $line = substr_count(substr($code, 0, $offset + $script[1][1]), "\n") + 1;
                $rel  = $dir . '/' . basename($file);

                if (preg_match('/(?<![\\\\{\w])\$(\w+)/', $js, $bare)) {
                    fwrite(STDERR, "{$rel}:{$line} — bare \${$bare[1]} in a heredoc script: escape it as \\\${$bare[1]} "
                        . "or wrap the PHP value as {\$var}\n");
                    $failures++;
                    continue;
                }
                if ($node === '') {
                    continue;
                }

                // `\$` is jQuery, `{$var}` is a PHP value: both become plain JS.
                $parsable = str_replace('\\$', '$', $js);
                $parsable = preg_replace('/\{\$[^}]*\}/', 'PHPVAL', $parsable);
                file_put_contents($tmp, $parsable);
                exec(escapeshellarg($node) . ' --check ' . escapeshellarg($tmp) . ' 2>&1', $out, $status);
                if ($status !== 0) {
                    $reason = '';
                    foreach ($out as $outLine) {
                        if (str_contains($outLine, 'Error')) {
                            $reason = trim($outLine);
                            break;
                        }
                    }
                    fwrite(STDERR, "{$rel}:{$line} — inline script does not parse: {$reason}\n");
                    $failures++;
                }
                $out = [];
            }
        }
    }
}

@unlink($tmp);

if ($failures > 0) {
    fwrite(STDERR, "Inline JS check failed ({$failures} of {$blocks} blocks)\n");
    exit(1);
}
echo $node === ''
    ? "Inline JS OK ({$blocks} blocks, escape rule only — node not installed)\n"
    : "Inline JS OK ({$blocks} blocks parsed)\n";
