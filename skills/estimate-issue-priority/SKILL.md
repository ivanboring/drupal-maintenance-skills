---
name: estimate-issue-priority
description: Use when asked to estimate, triage, or set the priority of a GitLab issue from its URL. Reads the issue and its comments, picks a priority against fixed criteria, and sets a scoped priority::{priority} label — without ever overwriting a priority that already exists.
---

# Estimate Issue Priority

Given a GitLab issue, evaluate its impact against a fixed priority rubric and set the
issue's **priority** as a scoped `priority::{priority}` label. The priority is written
automatically once decided, and an issue that already has a priority label is never
changed. No comment is posted.

## Input

Either of two things:

- A **single GitLab issue URL** — prioritize that one issue.
- A **project URL or path** (e.g. `https://gitlab.example.com/group/project`) — find
  every open issue with no priority and prioritize them all. See *Bulk mode* below.

A single issue URL looks like:

```
https://gitlab.example.com/group/project/-/issues/42
```

A bare issue number is not enough — issue numbers repeat across projects, so they
cannot identify an issue on their own. The URL carries the project. (`OWNER/REPO#42`
works as an equivalent.)

If the user gives only a number, ask for the full issue URL before continuing.

## Tools

Four scripts in this skill's `scripts/` directory do all the GitLab work. Use only
these; do not call any other command to read or change issues or labels.

- `scripts/glab-issue-show.sh <issue-url>` — prints the issue's title, state, labels,
  current priority, description, and comments.
- `scripts/glab-issue-prioritize.sh <issue-url> <priority>` — sets the
  `priority::{priority}` label. It refuses (writes nothing, non-zero exit) if a
  priority label already exists.
- `scripts/glab-issues-unprioritized.sh <project-url-or-path>` — prints, one web URL
  per line, every **open** issue in the project that has no `priority::` label. A
  summary count goes to stderr. Use it to drive a whole-project pass.
- `scripts/glab-labels-ensure.sh <project-url-or-path> [--create]` — without a flag,
  reports which of the four `priority::` labels exist and which are missing (creates
  nothing). With `--create`, creates the missing ones with color `#ff5353`.

Run them from the repository root, e.g.
`.agents/skills/estimate-issue-priority/scripts/glab-issue-show.sh <issue-url>`.

## Procedure (single issue)

1. **Read the issue.** Run `glab-issue-show.sh <issue-url>`.
2. **Stop if already prioritized.** If the `PRIORITY:` line is anything other than
   `none`, do not change it. Tell the user the issue already has that priority and stop.
3. **Evaluate the impact** against the rubric below, using the title, description, and
   comments. Weigh how many users are affected, whether a released version is involved,
   and the severity of the failure (cosmetic vs. submodule failure vs. data loss).
4. **Pick a priority** from the rubric. Be conservative: `major` and `critical` are
   reserved for the criteria below and nothing else.
5. **Set it.** Run `glab-issue-prioritize.sh <issue-url> <priority>`.
6. **Report the result** to the user: the chosen priority. If the script prints
   `REFUSED` or `FAILED`, relay that plainly and do not retry blindly.

## Bulk mode (whole project)

When given a project rather than a single issue, prioritize every open issue that has
no priority yet:

1. **Ensure the labels exist.** Run `glab-labels-ensure.sh <project>` (no flag) to
   check. If it reports any `MISSING` label, **ask the user to confirm** before
   creating them — show which are missing and that they will be created with color
   `#ff5353`. Only after the user agrees, run `glab-labels-ensure.sh <project>
   --create`. Never pass `--create` without that confirmation.
2. **List the work.** Run `glab-issues-unprioritized.sh <project>` to get the open,
   unprioritized issue URLs (one per line). Relay the summary count to the user.
3. **Prioritize each one** by running the single-issue procedure above for every URL
   in the list. The `glab-issue-prioritize.sh` guard still protects each issue, so a
   race that prioritizes an issue meanwhile is handled safely (it returns `REFUSED`).
4. **Report a tally** at the end: how many issues were prioritized and the breakdown
   by priority, plus any that were `REFUSED`/`FAILED`.

## Priority rubric

| Priority | Criteria |
|----------|----------|
| `minor`    | Minor issues like misspellings, minor design issues affecting very few devices, really edge-case bugs, and things that can easily wait. |
| `normal`   | Almost all issues, if not fitting the other priorities, belong here. **Security note:** if a user has falsely set a security issue publicly, still set it to `normal` so as not to raise suspicion. |
| `major`    | Failure of a submodule, subsystem, or similar that affects most users of that submodule/subsystem on a released version. Set this carefully. |
| `critical` | Failures that create a total white screen of death, kill other modules, or cause data loss. All hands on deck — a new release is needed now. Only ever set if one of these criteria fits. |

When in doubt between two priorities, choose the **lower** one — `major` and `critical`
must genuinely meet their criteria.

## Security handling

A publicly filed security issue must **not** be flagged with an elevated priority, even
if its impact would otherwise warrant `major` or `critical`. Raising its priority
signals to onlookers that the report is real and exploitable. Set such issues to
`normal` regardless of apparent severity.
