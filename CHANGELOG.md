# Changelog

What changed, newest first, in pig's format: `## [x.y.z] - YYYY-MM-DD`, then only the sections that
have entries (`### New Features`, `### Added`, `### Changed`, `### Fixed`). Notes under
`## Unreleased` belong to no version yet; the version itself lives in the git tag.

## [0.1.0] - 2026-11-02

### New Features

- The gate as a package of its own: `pig install git:github.com/owner888/pig-permission-gate`. Blocks `rm -rf /` and `~`; asks before privileged, disk-wiping, history-rewriting and power commands, read the way the shell splits them (`"su""do"`, `S=sudo; $S`, `bash -c "sudo …"`), and blocks them where no one can be asked; turns every other `rm` — `sudo rm` too — into `trash`, or into a move to `_to_delete/` where there is no `trash`; leaves build output and caches to `rm`, and leaves alone an `rm` that runs on another machine (`adb shell rm`, `ssh host "rm …"`) or sits in quotes.
