# Project skills

Skills in this folder load automatically in any Claude Code session opened in this repository, without installing a plugin.

## Sources

| Library | Version | Licence | Contents here |
|---|---|---|---|
| [Academic Research Skills](https://github.com/Imbad0202/academic-research-skills) (Cheng-I Wu) | 3.23.1 | CC BY-NC 4.0 (non-commercial use only; see `LICENSES/`) | `academic-paper`, `academic-paper-reviewer`, `academic-pipeline`, `deep-research`, `sr-screener`, plus the support folders `shared/`, `scripts/` and `agents/` |
| [AI-Research-SKILLs](https://github.com/Orchestra-Research/AI-Research-SKILLs) (Orchestra Research) | main, October 2026 | MIT (see `LICENSES/`) | 98 skill folders, flattened from the repository's numbered category folders (`0-autoresearch-skill` is copied as `autoresearch`) |

## Notes

- The ARS skills refer to `shared/`, `scripts/` and `agents/` by relative paths, so those folders must stay alongside the skill folders. They contain no `SKILL.md` and are not skills themselves.
- ARS test files and test fixtures were not copied. Its hooks, slash commands and plugin agents come only with the plugin, which remains enabled in `.claude/settings.json`.
- To update a library, re-copy its skill folders from the upstream repository and update the version in this table.
