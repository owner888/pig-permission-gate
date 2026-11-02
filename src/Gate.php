<?php

declare(strict_types=1);

namespace PigPermissionGate;

/**
 * What the gate decides about a bash command, apart from pig: which commands to block, which to
 * ask about, and how `rm` is turned into a move that can be undone.
 */
final class Gate
{
    /** Directories that are only ever build output or caches: `rm` of them runs as written. */
    private const array SAFE_TARGETS = [
        'dist' => true, 'build' => true, 'out' => true, 'target' => true, 'bin' => true, 'obj' => true,
        '.next' => true, '.nuxt' => true, '.turbo' => true, '.svelte-kit' => true, '.output' => true,
        'node_modules' => true, 'vendor' => true, '.cache' => true, 'coverage' => true,
        'tmp' => true, 'temp' => true, '.undodir' => true,
    ];

    private const string RECURSIVE_OR_FORCED_RM = '/\brm\s+(-[a-zA-Z]*[rfR][a-zA-Z]*|--recursive|--force)\b/i';

    /** Programs that run what follows them as another user. */
    private const array PRIVILEGED = ['sudo' => true, 'doas' => true, 'su' => true, 'pkexec' => true, 'run0' => true];

    /**
     * A program name only means something in **command position**: the start of the line, or
     * after `;` `&&` `||` `|` `(` `$(` or a backtick, past any `VAR=value` assignments and the
     * wrappers that run what follows them. Matched anywhere, `grep shutdown file` and
     * `git log | grep "Suggest su"` asked for confirmation — the word was data, not a command.
     * A quote counts as a separator too, which only matters where quotes were kept — the string
     * handed to `sh -c` or `ssh` starts a command of its own.
     */
    private const string AT = '(?:^|[;&|(`\n"\']|\$\()\s*(?:\w+=\S*\s+)*(?:(?:exec|nohup|time|command|env)\s+)*';

    private static ?bool $trash = null;

    /** `rm -rf /`, `rm -rf ~` and the like: blocked outright. */
    public static function isCatastrophic(string $command): bool
    {
        if (preg_match(self::RECURSIVE_OR_FORCED_RM, $command) !== 1) {
            return false;
        }

        preg_match_all('/(?:[^\s\'"]+|"[^"]*"|\'[^\']*\')+/', $command, $matches);
        foreach ($matches[0] as $token) {
            if (in_array(trim($token, '\'"'), ['/', '/*', '~', '~/', '$HOME'], true)) {
                return true;
            }
        }

        return false;
    }

    /** Whether every `rm` in the command removes only build output, caches or logs. */
    public static function removesOnlyBuildOutput(string $command): bool
    {
        $found = false;

        foreach (preg_split('/&&|\|\||;|\|/', $command) ?: [] as $part) {
            if (preg_match('/\brm\s+(.+)$/i', trim($part), $match) !== 1) {
                continue;
            }

            $found = true;
            preg_match_all('/(?:[^\s\'"]+|"[^"]*"|\'[^\']*\')+/', $match[1], $tokens);
            $targets = array_filter($tokens[0], static fn (string $t): bool => !str_starts_with($t, '-'));

            if ($targets === []) {
                return false;
            }
            foreach ($targets as $target) {
                if (!self::isBuildOutput($target)) {
                    return false;
                }
            }
        }

        return $found;
    }

    private static function isBuildOutput(string $target): bool
    {
        $clean = trim($target, '\'"');

        if ($clean === '' || in_array($clean, ['.', '*', '.*', '/*'], true)) {
            return false;
        }
        if (str_starts_with($clean, '/') || str_starts_with($clean, '~') || str_contains($clean, '..')) {
            return false;
        }
        if (str_contains($clean, '$') || str_contains($clean, '`')) {
            return false;
        }

        $root = explode('/', (string) preg_replace('#^\./#', '', rtrim($clean, '/')))[0];

        return isset(self::SAFE_TARGETS[$root]) || str_ends_with($clean, '.log') || str_ends_with($clean, '.tmp');
    }

    /** Whether there is a `trash` (`which trash`): macOS 14 and later ship one; Termux and most Linux do not. */
    public static function hasTrash(): bool
    {
        if (self::$trash !== null) {
            return self::$trash;
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_file("{$dir}/trash") && is_executable("{$dir}/trash")) {
                return self::$trash = true;
            }
        }

        return self::$trash = false;
    }

    /**
     * Turns every `rm` this shell runs — `rm`, `rm -r`, `rm -rf`, `sudo rm` — into a move that can
     * be undone: to the Trash with `trash`, or, with no `trash`, into `_to_delete/<time>-<pid>/`
     * under the working directory. With `-f` only the paths that exist are moved, so `rm -f` of
     * nothing still succeeds.
     *
     * Only an `rm` in command position (start, or after `;` `&` `|` `(` `$(` a backtick or a
     * newline, `sudo` allowed) and not inside quotes. `adb shell rm …`, `ssh host "rm …"` and
     * `git commit -m "rm …"` hand the word to another program — on a phone or a server there is no
     * Trash of this machine to move to, and rewriting `adb shell "rm -f a"` cut the quote in half
     * and left a command bash could not parse. Behind other wrappers (`xargs rm`, `nohup rm`) the
     * replacement is not valid shell, so those run as written. Redirections stay outside.
     */
    public static function rewriteRm(string $command, bool $trash): string
    {
        // Which bytes are inside quotes, read the way the shell reads them.
        $quoted = [];
        $quote = null;
        for ($i = 0, $n = strlen($command); $i < $n; $i++) {
            $c = $command[$i];
            if ($quote === null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === '"' || $c === "'") {
                    $quote = $c;
                    $quoted[$i] = true;
                }
                continue;
            }
            $quoted[$i] = true;
            if ($quote === '"' && $c === '\\') {
                $quoted[++$i] = true;
            } elseif ($c === $quote) {
                $quote = null;
            }
        }

        $word = '(?:"[^"]*"|\'[^\']*\'|[^\s;&|\n()<>#`\'"])+';

        return preg_replace_callback(
            '/(^|[;&|(\n`])(\s*)((?:sudo\s+(?:-\S+\s+)*)?)rm((?:\s+-[a-zA-Z]+|\s+--[a-z-]*)*)((?:\s+(?!\d*[<>])' . $word . ')+)/',
            static function (array $m) use ($quoted, $trash): string {
                if (isset($quoted[$m[2][1] + strlen($m[2][0])])) {
                    return $m[0][0];
                }

                $sudo = $m[3][0];
                $force = preg_match('/(?:^|\s)-[a-zA-Z]*f|--force/', $m[4][0]) === 1;
                $paths = trim($m[5][0]);
                $dir = '"_to_delete/$(date +%Y%m%d-%H%M%S)-$$"';

                $replacement = match (true) {
                    $trash && !$force => "{$sudo}trash {$paths}",
                    $trash => "(for p in {$paths}; do [ -e \"\$p\" ] && {$sudo}trash \"\$p\"; done; true)",
                    !$force => "(d={$dir}; mkdir -p \"\$d\" && {$sudo}mv {$paths} \"\$d\"/)",
                    default => "(d={$dir}; mkdir -p \"\$d\"; for p in {$paths}; do [ -e \"\$p\" ] && {$sudo}mv \"\$p\" \"\$d\"/; done; true)",
                };

                // After `(`, a space: `$((` would start arithmetic.
                $lead = $m[1][0] === '(' && $m[2][0] === '' ? '( ' : $m[1][0] . $m[2][0];

                return $lead . $replacement;
            },
            $command,
            -1,
            $count,
            PREG_OFFSET_CAPTURE,
        ) ?? $command;
    }

    /** Privileged, disk-wiping, history-rewriting and power commands: asked about before they run. */
    public static function isDangerous(string $command): bool
    {
        if (self::hidesPrivilege($command)) {
            return true;
        }

        $at = self::AT;
        $patterns = [
            "/{$at}(sudo|doas|su)\\b/i",
            "/{$at}(chmod|chown|chgrp)\\s+(-[a-zA-Z]*R[a-zA-Z]*|--recursive|.*\\b777\\b)/i",
            "/{$at}git\\s+(reset\\s+--hard|clean\\s+-[a-zA-Z]*[fdx]|push\\s+.*(--force|-f\\b)|branch\\s+-[dD]|checkout\\s+\\.)/i",
            "/{$at}(dd\\s+if=|mkfs(\\.\\w+)?\\b|fdisk\\b|parted\\b|gdisk\\b)/i",
            '/>\s*\/dev\/(sd[a-z]|nvme\d|disk\d|rdisk\d)/i',
            "/{$at}(shutdown|reboot|poweroff|halt|init\\s+0)\\b/i",
            "/{$at}kill(all)?\\s+-9\\s+-1\\b/i",
        ];

        $text = self::commandText($command);
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the patterns are matched against: the command with its quoted strings blanked, so a
     * pattern in a `grep` or a message in a `git commit -m` is not read as a command. Except when
     * the command hands a string to another shell — `sh -c`, `eval`, `ssh`, `xargs` — because
     * there the quoted text *is* a command, and blanking it would wave `bash -c "sudo …"` through.
     */
    private static function commandText(string $command): string
    {
        if (preg_match('/\b(?:(?:ba|z|da|k)?sh\s+(?:-\w+\s+)*-c|eval|ssh|xargs|watch)\b/', $command) === 1) {
            return $command;
        }

        return preg_replace(['/\'[^\']*\'/', '/"(?:[^"\\\\]|\\\\.)*"/'], ["''", '""'], $command) ?? $command;
    }

    /**
     * The patterns read the text; a shell reads words. `"su""do"`, `s\udo` and `'sudo'` are all
     * `sudo` once the shell has joined them, `/usr/bin/sudo` is too, and `SUDO=sudo; $SUDO apt-get …`
     * runs it without the word ever standing where a command goes — all of which a model has been
     * seen writing, to get past exactly this gate. So the command is also split the way the shell
     * splits it, and it is the first word of each command, and what each variable is set to, that
     * is asked about.
     */
    private static function hidesPrivilege(string $command, int $depth = 0): bool
    {
        if ($depth > 3) {
            return false;
        }

        $names = implode('|', array_keys(self::PRIVILEGED));
        $variables = [];

        // `X=$(which sudo)` — the `$(` splits it before the words below see the value.
        if (preg_match_all('/([A-Za-z_]\w*)=\$\(\s*(?:which|command\s+-v|type\s+-p)\s+["\']?(?:' . $names . ')\b/', $command, $found)) {
            array_push($variables, ...$found[1]);
        }

        foreach (self::shellCommands($command) as $words) {
            // `X=sudo`, `export X="/usr/bin/su""do"`, `env X=sudo …`: wherever it is set.
            foreach ($words as $word) {
                if (preg_match('/^([A-Za-z_]\w*)=(.+)$/s', $word, $m) === 1 && self::isPrivileged($m[2])) {
                    $variables[] = $m[1];
                }
            }

            $rest = $words;
            while ($rest !== [] && (preg_match('/^[A-Za-z_]\w*=/', $rest[0]) === 1 || in_array($rest[0], ['exec', 'nohup', 'time', 'command', 'env', 'nice'], true))) {
                array_shift($rest);
            }
            if ($rest === []) {
                continue;
            }

            if (self::isPrivileged($rest[0])) {
                return true;
            }

            // A string handed to another shell is a command of its own.
            if (preg_match('/^(?:ba|z|da|k)?sh$/', basename($rest[0])) === 1 && ($at = array_search('-c', $rest, true)) !== false && isset($rest[$at + 1])) {
                if (self::hidesPrivilege($rest[$at + 1], $depth + 1)) {
                    return true;
                }
            } elseif ($rest[0] === 'eval' && self::hidesPrivilege(implode(' ', array_slice($rest, 1)), $depth + 1)) {
                return true;
            }
        }

        foreach (array_unique($variables) as $name) {
            if (preg_match('/\$\{?' . $name . '(?!\w)/', $command) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function isPrivileged(string $word): bool
    {
        $program = preg_split('/\s+/', trim($word))[0] ?? '';

        return isset(self::PRIVILEGED[basename($program)]);
    }

    /**
     * The words of each simple command, as the shell splits them: quotes and backslashes resolved,
     * a new command after `;` `&` `|` `(` `$(` a backtick or a newline.
     *
     * @return list<list<string>>
     */
    private static function shellCommands(string $command): array
    {
        $commands = [];
        $words = [];
        $word = '';
        $inWord = false;
        $quote = null;
        $length = strlen($command);

        $endWord = static function () use (&$words, &$word, &$inWord): void {
            if ($inWord) {
                $words[] = $word;
            }
            $word = '';
            $inWord = false;
        };
        $endCommand = static function () use (&$commands, &$words, $endWord): void {
            $endWord();
            if ($words !== []) {
                $commands[] = $words;
            }
            $words = [];
        };

        for ($i = 0; $i < $length; $i++) {
            $c = $command[$i];

            if ($quote === "'") {
                $c === "'" ? $quote = null : $word .= $c;
                continue;
            }
            if ($quote === '"') {
                if ($c === '"') {
                    $quote = null;
                } elseif ($c === '\\' && $i + 1 < $length) {
                    $word .= $command[++$i];
                } else {
                    $word .= $c;
                }
                continue;
            }

            if ($c === "'" || $c === '"') {
                $quote = $c;
                $inWord = true;
            } elseif ($c === '\\' && $i + 1 < $length) {
                $word .= $command[++$i];
                $inWord = true;
            } elseif ($c === '$' && ($command[$i + 1] ?? '') === '(') {
                $i++;
                $endCommand();
            } elseif (str_contains(";&|\n()`", $c)) {
                $endCommand();
            } elseif ($c === ' ' || $c === "\t" || $c === "\r") {
                $endWord();
            } else {
                $word .= $c;
                $inWord = true;
            }
        }
        $endCommand();

        return $commands;
    }
}
