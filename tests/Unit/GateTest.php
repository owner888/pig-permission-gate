<?php

declare(strict_types=1);

namespace PigPermissionGate\Test\Unit;

use PHPUnit\Framework\TestCase;
use PigPermissionGate\Gate;

final class GateTest extends TestCase
{
    public function testDeletingRootOrHomeIsCatastrophic(): void
    {
        foreach (['rm -rf /', 'rm -rf ~/', 'rm -fr /*', 'rm -rf "$HOME"'] as $command) {
            $this->assertTrue(Gate::isCatastrophic($command), $command);
        }
        $this->assertFalse(Gate::isCatastrophic('rm -rf /tmp/x'));
        $this->assertFalse(Gate::isCatastrophic('ls /'), 'not an rm');
    }

    public function testPrivilegedAndDestructiveCommandsAreDangerous(): void
    {
        $dangerous = [
            'sudo apt-get install x',
            '"su""do" ls',                  // joined by the shell
            's\udo ls',
            '/usr/bin/sudo ls',
            'S=sudo; $S ls',                // never stands where a command goes
            'X=$(which sudo); $X ls',
            'bash -c "sudo ls"',            // a string another shell runs
            'eval "sudo ls"',
            'git reset --hard',
            'git push -f origin main',
            'git clean -fdx',
            'dd if=/dev/zero of=x',
            'chmod -R 777 .',
            'echo > /dev/sda',
            'reboot',
            'kill -9 -1',
        ];
        foreach ($dangerous as $command) {
            $this->assertTrue(Gate::isDangerous($command), $command);
        }
    }

    public function testTheWordAloneIsNotACommand(): void
    {
        foreach (['grep shutdown notes.txt', 'git log | grep "Suggest su"', 'git commit -m "git reset --hard is gone"', 'ls', 'echo sudoku'] as $command) {
            $this->assertFalse(Gate::isDangerous($command), $command);
        }
    }

    public function testBuildOutputIsRemovedAsAsked(): void
    {
        $this->assertTrue(Gate::removesOnlyBuildOutput('rm -rf node_modules'));
        $this->assertTrue(Gate::removesOnlyBuildOutput('rm -rf dist build && npm run build'));
        $this->assertTrue(Gate::removesOnlyBuildOutput('rm -f debug.log'));
        $this->assertFalse(Gate::removesOnlyBuildOutput('rm -rf node_modules src'), 'one path that is not build output');
        $this->assertFalse(Gate::removesOnlyBuildOutput('rm -rf ../vendor'));
        $this->assertFalse(Gate::removesOnlyBuildOutput('rm -rf $DIR'));
    }

    public function testRmGoesToTheTrash(): void
    {
        $this->assertSame('trash a.txt', Gate::rewriteRm('rm a.txt', true));
        $this->assertSame('sudo trash /opt/x', Gate::rewriteRm('sudo rm -r /opt/x', true));
        $this->assertSame(
            'cd /tmp && (for p in "my dir" foo; do [ -e "$p" ] && trash "$p"; done; true)',
            Gate::rewriteRm('cd /tmp && rm -rf "my dir" foo', true),
            '-f: only what exists, and success when nothing does',
        );
        $this->assertSame(
            '(for p in x; do [ -e "$p" ] && trash "$p"; done; true) 2>/dev/null && echo ok',
            Gate::rewriteRm('rm -f x 2>/dev/null && echo ok', true),
            'the redirection stays outside',
        );
        $this->assertSame(
            'echo $( (for p in a; do [ -e "$p" ] && trash "$p"; done; true))',
            Gate::rewriteRm('echo $(rm -f a)', true),
            'a space after `(`: `$((` would be arithmetic',
        );
    }

    public function testWithoutTrashRmMovesToToDelete(): void
    {
        $this->assertSame(
            '(d="_to_delete/$(date +%Y%m%d-%H%M%S)-$$"; mkdir -p "$d" && mv a.txt b.txt "$d"/)',
            Gate::rewriteRm('rm a.txt b.txt', false),
        );
        $this->assertSame(
            '(d="_to_delete/$(date +%Y%m%d-%H%M%S)-$$"; mkdir -p "$d"; for p in x; do [ -e "$p" ] && mv "$p" "$d"/; done; true)',
            Gate::rewriteRm('rm -rf x', false),
        );
    }

    public function testRmThatRunsElsewhereIsLeftAlone(): void
    {
        foreach ([
            'adb -s X shell rm -f /data/local/tmp/a.dex',   // on the phone
            'adb shell "rm -f /data/local/tmp/a.dex"',
            'ssh host "cd x; rm -rf y"',                  // on the server
            'git commit -m "rm -rf the old cache"',       // only words
            'find . -name "*.o" | xargs rm -f',           // behind a wrapper the loop is not valid shell
            'echo rm is a word',
        ] as $command) {
            foreach ([true, false] as $trash) {
                $this->assertSame($command, Gate::rewriteRm($command, $trash), $command);
            }
        }
    }

    public function testEveryRewriteIsValidShell(): void
    {
        $commands = ['rm -rf /tmp/a /tmp/b', 'rm a.txt', 'sudo rm -rf /opt/x', 'rm -f x 2>/dev/null && echo ok', 'git commit -m "rm -rf s"; rm -f a.txt', 'echo $(rm -f a)', '(cd x; rm -rf y)'];
        foreach ($commands as $command) {
            foreach ([true, false] as $trash) {
                $rewritten = Gate::rewriteRm($command, $trash);
                exec('bash -n -c ' . escapeshellarg($rewritten) . ' 2>&1', $output, $exit);
                $this->assertSame(0, $exit, "{$command} → {$rewritten}");
            }
        }
    }
}
