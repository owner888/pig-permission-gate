# pig-permission-gate

A permission gate for the pig coding agent. It stands between the model and `bash`: deletions become moves you can undo, dangerous commands wait for you, and `rm -rf /` never runs.

[中文](README.zh-CN.md)

## Installation

```bash
pig install git:github.com/owner888/pig-permission-gate
```

Nothing else: no Composer dependencies. Without the package, pig runs what the model writes as written.

## What it does

| The model runs | What happens |
| --- | --- |
| `rm -rf /`, `rm -rf ~` | Blocked. |
| `sudo …`, `dd if=`, `mkfs`, `git reset --hard`, `git push --force`, `git clean -fdx`, `chmod -R`, `shutdown` … | Waits for you to allow it; blocked where no one can be asked (print mode, RPC without a UI). |
| `rm`, `rm -r`, `rm -rf`, `sudo rm` | Becomes `trash` — a move to the Trash. Where there is no `trash` command (macOS before 14, Linux, Termux), a move into `_to_delete/<time>-<pid>/` under the working directory instead. With `-f`, only paths that exist are moved, so `rm -f` of nothing still succeeds. |
| `rm -rf node_modules`, `vendor`, `dist`, `build`, `.cache`, `*.log` … | Runs as written: build output and caches. |
| `adb shell rm …`, `ssh host "rm …"` | Runs as written: the files are on another machine, where this one's Trash cannot reach. |

Commands are read the way the shell reads them, so a word in a `grep` pattern or a commit message is not a command, while `"su""do"`, `/usr/bin/sudo`, `S=sudo; $S …` and `bash -c "sudo …"` are still `sudo`.

`_to_delete/` is not emptied for you: look through it and delete it yourself, and add it to `.gitignore` where it would show up in `git status`.

## Development

The tests run against a pig checkout:

```bash
PIG_DIR=~/path/to/pig ~/path/to/pig/vendor/bin/phpunit -c phpunit.xml
```

`PIG_DIR` defaults to a `pig` directory next to this package.

## License

Apache-2.0 — see [LICENSE](LICENSE) and [NOTICE](NOTICE).
