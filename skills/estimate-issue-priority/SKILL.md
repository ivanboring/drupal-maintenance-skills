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

A **GitLab issue URL**, for example:

```
https://gitlab.example.com/group/project/-/issues/42
```

A bare issue number is not enough — issue numbers repeat across projects, so they
cannot identify an issue on their own. The URL carries the project. (`OWNER/REPO#42`
works as an equivalent.)

If the user gives only a number, ask for the full issue URL before continuing.

## Tools

Two scripts in this skill's `scripts/` directory do all the GitLab work. Use only
these; do not call any other command to read or change the issue.

- `scripts/glab-issue-show.sh <issue-url>` — prints the issue's title, state, labels,
  current priority, description, and comments.
- `scripts/glab-issue-prioritize.sh <issue-url> <priority>` — sets the
  `priority::{priority}` label. It refuses (writes nothing, non-zero exit) if a
  priority label already exists.

Run them from the repository root, e.g.
`.agents/skills/estimate-issue-priority/scripts/glab-issue-show.sh <issue-url>`.

## Procedure

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
