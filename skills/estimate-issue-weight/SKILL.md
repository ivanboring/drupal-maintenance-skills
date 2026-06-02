---
name: estimate-issue-weight
description: Use when asked to estimate, size, or set the weight of a GitLab issue from its URL. Reads the issue and its comments, picks a t-shirt size against fixed criteria, and sets the issue weight plus a short explanatory comment — without ever overwriting a weight that already exists.
---

# Estimate Issue Weight

Given a GitLab issue, evaluate its scope against a fixed t-shirt-sizing rubric and set
the issue's **weight** together with a short explanatory comment. The estimate is
written automatically once decided, and an issue that already has a weight is never
changed.

## Input

Either of two things:

- A **single GitLab issue URL** — size that one issue.
- A **project URL or path** (e.g. `https://gitlab.example.com/group/project`) — find
  every issue with no weight and no `No Estimation Available` label and size them all.
  See *Bulk mode* below.

A single issue URL looks like:

```
https://gitlab.example.com/group/project/-/issues/42
```

A bare issue number is not enough — issue numbers repeat across projects, so they
cannot identify an issue on their own. The URL carries the project. (`OWNER/REPO#42`
works as an equivalent.)

If the user gives only a number, ask for the full issue URL before continuing.

## Tools

Five scripts in this skill's `scripts/` directory do all the GitLab work. Use only
these; do not call any other command to read or change the issue or its labels.

- `scripts/glab-issue-show.sh <issue-url>` — prints the issue's title, state, labels,
  current weight, description, and comments.
- `scripts/glab-issues-unweighted.sh <project-url-or-path> [--include-closed]` — prints,
  one web URL per line, every issue that still needs a weight estimate (no weight **and**
  no `No Estimation Available` label). Scans open issues by default; `--include-closed`
  also looks through closed ones. A summary count goes to stderr. Use it to drive a
  whole-project pass.
- `scripts/glab-issue-estimate.sh <issue-url> <weight> <comment>` — sets the weight
  and posts the comment in one step. It refuses (writes nothing, non-zero exit) if a
  weight already exists.
- `scripts/glab-labels-ensure.sh <project-url-or-path> [--create]` — without a flag,
  reports whether the `No Estimation Available` label exists or is missing (creates
  nothing). With `--create`, creates it if missing with color `#cd5b45`.
- `scripts/glab-issue-no-estimate.sh <issue-url> <comment>` — marks an issue as not
  estimatable: adds the `No Estimation Available` label and posts the comment. It
  refuses (writes nothing, non-zero exit) if a weight already exists. The label must
  already exist; ensure it first with `glab-labels-ensure.sh`.

Run them from the repository root, e.g.
`.agents/skills/estimate-issue-weight/scripts/glab-issue-show.sh <issue-url>`.

## Procedure

1. **Read the issue.** Run `glab-issue-show.sh <issue-url>`.
2. **Stop if already weighted.** If the `WEIGHT:` line is anything other than `none`,
   do not change it. Tell the user the issue already has that weight and stop.
   (A stored weight of `0` is reported as `none`: it is the drupal.org migration
   default, never a real estimate, so the skill is free to size the issue.)
3. **Estimate the time**, not the complexity. The weight is always how long the work
   takes a senior developer who knows this codebase — measured in working hours, never
   how clever or architecturally involved it is. A complex architecture that a senior
   can write quickly is a small weight; a conceptually simple change that requires
   touching dozens of files, waiting on reviews, or hours of careful testing is a large
   one. Read the title, description, and comments and ask only: *how many hours of
   hands-on work is this?* Factor in writing tests and documentation when they are
   needed, since they cost time — but do not inflate the estimate just because the
   problem sounds hard.
4. **Decide whether it is estimatable.** If the issue lacks enough information to size
   (no clear scope, missing reproduction, an open question that changes the answer) or
   is otherwise not estimatable, take the *Not estimatable* path below instead of
   picking a size.
5. **Pick a size and its weight** from the rubric.
6. **Write a two-sentence comment** (see rules below).
7. **Set it.** Run
   `glab-issue-estimate.sh <issue-url> <weight> "<comment>"`.
8. **Report the result** to the user: the chosen size, the weight, and the comment.
   If the script prints `REFUSED`, `FAILED`, or `PARTIAL`, relay that plainly and do
   not retry blindly.

## Not estimatable

When an issue cannot be sized, mark it with the `No Estimation Available` label instead
of guessing a weight:

1. **Ensure the label exists.** Run `glab-labels-ensure.sh <project>` (no flag) to
   check. If it reports `MISSING`, **ask the user to confirm** before creating it —
   say it will be created with color `#cd5b45`. Only after the user agrees, run
   `glab-labels-ensure.sh <project> --create`. Never pass `--create` without that
   confirmation.
2. **Write a two-sentence comment** following the comment rules below, stating that no
   estimate is available and the main reason (e.g. what information is missing).
3. **Apply it.** Run `glab-issue-no-estimate.sh <issue-url> "<comment>"`. The same
   no-overwrite guard applies: if the issue already has a weight it returns `REFUSED`.
4. **Report the result** to the user. If the script prints `REFUSED`, `FAILED`, or
   `PARTIAL`, relay that plainly and do not retry blindly.

## Bulk mode (whole project)

When given a project rather than a single issue, size every issue that still needs a
weight estimate:

1. **List the work.** Run `glab-issues-unweighted.sh <project>` to get the issue URLs
   that have no weight and no `No Estimation Available` label (one per line). Add
   `--include-closed` only if the user asks to cover closed issues too; by default it
   scans open issues. Relay the summary count to the user.
2. **Size each one** by running the single-issue procedure above for every URL in the
   list — picking a weight, or taking the *Not estimatable* path where appropriate. The
   no-overwrite guards still protect each issue, so a race that weights an issue
   meanwhile is handled safely (it returns `REFUSED`).
3. **Report a tally** at the end: how many issues were weighted, how many were marked
   `No Estimation Available`, and any that were `REFUSED`/`FAILED`/`PARTIAL`.

## Sizing rubric

The weight is an **upper bound in working hours**: it means "expected to take *less
than* this many hours", counting an 8-hour day. So a weight of `16` is a job of up to
two days, `8` is up to one day, `4` is up to half a day, and so on. Each size doubles
the one below it.

The criteria below are **time anchors**, not complexity grades. Each example names a
task and the hours it typically costs a senior developer who knows the codebase. Match
an issue to the row whose *time* it resembles, not the row whose subject sounds
similar — a sophisticated change a senior can finish in an afternoon is an `S`, even if
the architecture is involved.

| Size | Weight | Means | Criteria |
|------|--------|-------|----------|
| XXS | 1  | < 1 hour     | Fixing a typo, a label, or spacing in the UI. A trivial one-line change. Needs no test scripts or documentation — or the issue itself is fixing a typo in documentation. |
| XS  | 2  | < 2 hours    | A small, localized bugfix or config change with an obvious cause. Usually a single file and maybe one test. |
| S   | 4  | < 4 hours    | Adding a new button to a form, or a small self-contained UI tweak. Potentially writing a test script and a line of documentation. |
| M   | 8  | < 8 hours (1 day)   | Creating a simple API endpoint — about a day of hands-on work. Usually requires documentation and test scripts. |
| L   | 16 | < 16 hours (2 days)  | Building a full user profile page, including backend integration — roughly two days of work, including any tests and breaking-change handling. |
| XL  | 32 | < 32 hours (4 days)  | A full feature spanning several components, around four days of work end to end. Often worth splitting into multiple issues. |
| XXL | 64 | < 64 hours (over a week) | An architectural rewrite — over a week of hands-on work even for a senior. Should be split into discussion issues, meta issues, and creation issues. Very rare — e.g. rewriting Drupal AI to use Symfony AI. |

When an issue sits between two sizes, prefer the larger one and say why in the comment.

## Comment rules

The comment must:

- be **exactly two sentences**;
- **start with** `Automated Estimation: `;
- state which size/weight was chosen and the main reason for it — or, when the issue is
  not estimatable, state that no estimate is available and why.

Example (sized):

```
Automated Estimation: Sized as M (weight 8) because it adds a new API endpoint with
its own validation and tests but needs no architecture changes. The work is expected to
take less than one full day including documentation.
```

Example (not estimatable):

```
Automated Estimation: No estimate available because the issue does not describe the
expected behavior or steps to reproduce. Marked with "No Estimation Available" until the
scope is clarified.
```
