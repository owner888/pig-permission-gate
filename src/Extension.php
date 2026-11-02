<?php

declare(strict_types=1);

namespace PigPermissionGate;

use Pig\CodingAgent\Hooks\Events\ToolCallEvent;
use Pig\CodingAgent\Hooks\HookApi;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\Hooks\Results\ToolCallEventResult;

/**
 * Stands between the model and `bash`:
 *
 * - `rm -rf /`, `rm -rf ~` and the like are blocked;
 * - privileged, disk-wiping, history-rewriting and power commands (`sudo`, `dd`, `mkfs`,
 *   `git reset --hard`, `git push --force`, `shutdown` …) wait for the person to allow them, and
 *   are blocked where no one can be asked;
 * - every other `rm` becomes a move that can be undone — to the Trash, or to `_to_delete/` where
 *   there is no `trash` — except of build output and caches (`node_modules`, `vendor`, `dist` …),
 *   which is removed as asked.
 */
final class Extension
{
    public static function register(HookApi $pi): void
    {
        $pi->on('tool_call', static function (ToolCallEvent $event, HookContext $ctx): ?ToolCallEventResult {
            if ($event->toolName !== 'bash') {
                return null;
            }

            $command = (string) ($event->input['command'] ?? '');

            if (Gate::isCatastrophic($command)) {
                return new ToolCallEventResult(block: true, reason: "Blocked: this would delete the root or home directory: {$command}");
            }

            if (Gate::isDangerous($command)) {
                if (!$ctx->hasUi) {
                    return new ToolCallEventResult(block: true, reason: "Blocked: a dangerous command, and no one here to allow it: {$command}");
                }

                if (!$ctx->ui->confirm('Permission gate', "A potentially dangerous command:\n\n  {$command}\n\nRun it?")) {
                    return new ToolCallEventResult(block: true, reason: "Blocked by the user: {$command}");
                }
            }

            if (preg_match('/\brm\b/', $command) !== 1 || Gate::removesOnlyBuildOutput($command)) {
                return null;
            }

            $trash = Gate::hasTrash();
            $rewritten = Gate::rewriteRm($command, $trash);
            if ($rewritten === $command) {
                return null; // every `rm` here runs on another machine, or is only a word
            }

            // The event's input is what the tool runs with, so editing it in place is the rewrite.
            $event->input['command'] = $rewritten;
            if ($ctx->hasUi) {
                $ctx->ui->notify($trash ? 'rm → trash: moved to the Trash' : 'rm → _to_delete/: no `trash` here, moved there instead', 'info');
            }

            return null;
        });
    }
}
