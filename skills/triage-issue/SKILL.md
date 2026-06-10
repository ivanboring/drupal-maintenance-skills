---
name: triage-issue
description: Use when asked to triage, estimate, size, prioritize, or categorize GitLab issues. First checks for duplicates (closing them) and missing information (marking why::needsInfo), then sets weight, priority, and category against fixed rubrics and marks the issue state::accepted once all three are decided — only on projects configured in config/config.php, and never overwriting a value that already exists.
# Read-only enforcement: while this skill is active, file-editing tools and git
# are removed from Claude's tool pool. Triage only reads issues and sets
# labels/weight through the GitLab API CLI — it never edits local files or runs
# git. (Enforced by the harness for the duration of the skill — see "Security
# model".)
disallowed-tools:
  - Edit
  - Write
  - NotebookEdit
  - Bash(git *)
  - Bash(* && git *)
  - Bash(glab *)
  - Bash(* && glab *)
  - Bash(curl *)
  - Bash(* && curl *)
---

# Triage Issue

Triage a GitLab issue in two phases. **Two presteps gate the work** — an issue that
fails either is handled and the pipeline stops there:

- **Prestep 1 — Duplicate check.** Search the queue; if the issue duplicates an open one,
  comment, close it, and mark it a duplicate. Stop.
- **Prestep 2 — Information check.** Compare against the issue templates; if required
  information is missing, mark `why::needsInfo` and ask for it. Stop.

**Only if both presteps pass**, decide and record three things, then accept:

1. **Weight** — a t-shirt size written as the issue's numeric weight (plus a comment).
2. **Priority** — a scoped `priority::{priority}` label.
3. **Category** — a scoped `category::{category}` label.

Once all three are decided, mark the issue **`state::accepted`**. Each value is written
automatically once chosen, and an issue that already has a given value is never changed.

## Security model — read this first

Every command goes through `lib/gitlab.php`, which talks to the GitLab REST API v4
directly. There is **no `glab` and no `jq`**. A command runs **only** when:

- the issue/project resolves to a project key in **`config/config.php`**, and
- that project has a **non-empty token**.

Anything else is **hard-refused** before any network call. To set up, copy
`config/config.example.php` to `config/config.php` and add a token per project.

**Read-only on the local machine.** The only writes this skill makes are GitLab
comments and labels, via the allowlisted API. It never edits local files or runs git, and
this is **enforced**: the skill's frontmatter lists `Edit`, `Write`, `NotebookEdit`, and
`Bash(git *)` under `disallowed-tools`, so for as long as the skill is active the harness
removes the file-editing tools from the pool and blocks every `git` command. (The
restriction is scoped to the skill's run, so invoke it via `/triage-issue` and let it
finish in that turn.)

## Input

Either of two things:

- A **single GitLab issue URL** — triage that one issue.
- A **project URL or path** — find every open issue with no `state::` label and triage
  them all. See *Bulk mode* below.

A single issue URL looks like:

```
https://git.drupalcode.org/group/project/-/issues/42
https://git.drupalcode.org/group/project/-/work_items/42
```

A bare issue number is not enough — issue numbers repeat across projects. The URL
carries the project. If the user gives only a number, ask for the full issue URL.

## Tool

One script does all the GitLab work — a subcommand CLI. Run it with PHP from the
repository root:

```
php skills/triage-issue/scripts/triage.php <command> ...
```

| Command | Purpose |
|---|---|
| `list-pre-triage <project>` | Open issues with **no `state::` label** and **not** `why::needsInfo` (one web URL per line; SUMMARY on stderr). |
| `show <issue-url>` | Title, state, labels, weight, priority, category, triage state, description, comments. |
| `search <project> <query>` | Full-text search the open queue (title + description). One match per line: `state⇥web_url⇥title`. For the duplicate prestep. |
| `templates <project> [name]` | List issue template keys, or print one template's content. For the information prestep. |
| `mark-duplicate <issue-url> <duplicate-url> [comment] [--force]` | Comment (naming the project managers without `@`, so the reporter can tag them if they disagree), then close + `why::duplicate` + `state::closed`. Refuses if already closed/stated. |
| `mark-needs-info <issue-url> <comment> [--force]` | Add `why::needsInfo` and post what's missing. Refuses if already marked. |
| `weight <issue-url> <weight> [--force]` | Set the weight (`1\|2\|4\|8\|16\|32\|64`). **No comment** — the reasoning goes into the single accept comment. Refuses if already decided. **A weight over 16 (32/64) auto-tags the project managers, sets `why::needsInfo`, and must NOT be accepted.** |
| `no-weight <issue-url> <comment> [--force]` | Mark `No Estimation Available` and post the comment. Refuses if already weighted. |
| `priority <issue-url> <priority> [--force]` | Set `priority::{minor\|normal\|major\|critical}`. Refuses if one exists. |
| `category <issue-url> <category> [--force]` | Set `category::{bug\|feature\|meta\|plan\|support\|task}`. Refuses if one exists. |
| `accept <issue-url> <comment>` | Post the **single** `Automated Triage` `<comment>` (one sentence each on the weight, category, and priority) and set `state::accepted` — only once weight, priority, and category are all decided. On `major`/`critical`, the developer maintainers are tagged in the comment. |
| `labels-ensure <project> [--create]` | Check (or with `--create`, create) every label the skill uses. |

The script prints `OK:` on success and `REFUSED:`/`FAILED:` (non-zero exit) otherwise.
Relay those plainly and do not retry blindly.

### Overwriting an existing value (`--force`)

By default the setters never overwrite a value that is already set. There is **one
exception**: when the user **explicitly names a single issue** and asks to (re)check or
change a specific value, pass `--force` to that setter to overwrite it. For a scoped
label this swaps the old value for the new one; for weight it replaces the estimate
(clearing any `No Estimation Available` waiver).

`--force` is **only** for a single, explicitly identified issue. **Never** use it in
bulk mode or across multiple issues. Examples:

- ✅ "Could you recheck priority on issue 1234 on the AI module" → allowed, use `--force`.
- ❌ "Could you recheck priority on all issues that are also accepted" → not allowed; do
  not use `--force`. Decline and explain that re-checks are per named issue only.

## Procedure (single issue)

1. **Read the issue.** Run `triage.php show <issue-url>`. It prints `WEIGHT`, `PRIORITY`,
   `CATEGORY`, and `TRIAGE_STATE` lines (each `none` when unset; a stored weight of `0`
   is the drupal.org migration default and reads as `none`).

> **The two presteps are gates. If either one fires, the triage is finished for this
> issue — STOP immediately. Do NOT run `weight`, `priority`, `category`, or `accept`.**
> (The script enforces this too: those commands refuse on a gated issue. But you should
> stop on your own — do not even attempt them.)

2. **Prestep 1 — Duplicate check.** Run `triage.php search <project> "<key terms>"` with
   distinctive words from the title/description. Read the candidates (ignore the issue's
   own URL) and judge whether any **open** one is genuinely the same problem — not merely
   related. See *Duplicate check* below.
   - **If it is a duplicate:** run `triage.php mark-duplicate <issue-url> <duplicate-url>`,
     report it, and **STOP HERE.** Do not estimate or accept. The command comments,
     closes the issue, and sets `why::duplicate` + `state::closed`.
   - Otherwise continue to Prestep 2.

3. **Prestep 2 — Information check.** Identify the relevant template (a bug → `bug_report`,
   a feature → `feature_request`, etc.) with `triage.php templates <project>` and
   `triage.php templates <project> <name>`. Check the issue has filled in the
   information the template asks for. See *Information sufficiency check* below.
   - **If required information is missing:** run
     `triage.php mark-needs-info <issue-url> "<what is missing>"`, report it, and
     **STOP HERE.** Do not estimate or accept. The issue drops off the pre-triage list
     until the reporter removes `why::needsInfo`.
   - Otherwise continue to the estimation steps.

4. **Estimate.** *(Only reached when both presteps passed.)* For each of the three
   dimensions still `none`, decide and set it using the
   rubrics below (skip any already set — the script refuses to overwrite anyway):
   - **Weight:** pick a size and run `triage.php weight <url> <weight>` — **no comment**;
     the weight reasoning goes into the accept comment. If the issue cannot be sized, run
     `triage.php no-weight …` instead.
     - **If the weight is over 16 (i.e. 32 or 64): this is a third gate.** The `weight`
       command automatically tags the project managers in a comment and sets
       `why::needsInfo`. **STOP HERE** — do not set priority/category and do not accept.
       The issue goes to the project managers, who remove `why::needsInfo` if they agree
       it should proceed.
   - **Priority:** run `triage.php priority …`.
   - **Category:** run `triage.php category …`.

5. **Accept it.** Once weight, priority, and category are all decided, run
   `triage.php accept <issue-url> "<comment>"`. This posts the **single** triage comment,
   starting with `Automated Triage:`, giving **one sentence each on why you chose the
   weight, the category, and the priority** (the script appends a maintainer tag
   automatically when the priority is `major` or `critical`). `accept` sets
   `state::accepted` and refuses if anything is still missing, so it is safe to always
   call it last.

6. **Report** the outcome: duplicate / needs-info / flagged-for-PM-review (weight > 16) /
   or the chosen weight, priority, category and whether it was accepted.

## Bulk mode (whole project)

1. **Ensure labels exist.** Run `triage.php labels-ensure <project>` (no flag). If it
   reports any `MISSING`, **ask the user to confirm** before creating them, then run
   `triage.php labels-ensure <project> --create`. Never pass `--create` without that
   confirmation.
2. **List the work.** Run `triage.php list-pre-triage <project>` to get the open issues
   with no `state::` label (issues labelled `why::needsInfo` are skipped — they are
   waiting on the reporter). Relay the summary count.
3. **Triage each one** with the full single-issue procedure above — both presteps first,
   then the three estimates and accept.
4. **Report a tally:** how many issues were closed as duplicates, marked `why::needsInfo`
   (missing info *or* flagged for PM review at weight > 16), and accepted; the breakdown
   of weight / priority / category; how many were marked `No Estimation Available`; and
   any `REFUSED`/`FAILED`.

---

## Duplicate check

Search the open queue with the most distinctive terms from the issue (specific feature
names, error strings, submodule names) rather than generic words. From the results,
treat an issue as a duplicate **only** when it is the *same underlying problem or
request*, still **open**, and not the issue itself — closely related or adjacent issues
are not duplicates.

When you mark a duplicate, `mark-duplicate` posts a comment pointing the reporter to the
original, tells them to continue there, and names the `project_managers_to_tag` from
config **without an `@`** (so they are not pinged) — the reporter can tag them if they
disagree. It then closes the issue and sets `why::duplicate` + `state::closed`. Pass the **original** issue's URL as
`<duplicate-url>`. Be conservative: if unsure, do **not** mark a duplicate — continue the
normal pipeline.

## Information sufficiency check

Each issue type has a template (`bug_report`, `feature_request`, `plan`, `support_request`,
`task`). Fetch the relevant one and check the issue has *partially* filled in what it
asks for — enough to act on, not every field. For a bug that usually means a
description, steps to reproduce, and expected vs. actual behaviour; for a feature, the
problem/motivation and a proposed resolution.

If the essentials are missing, run `mark-needs-info` with a short, specific list of what
is missing. The command appends a note telling the reporter to remove `why::needsInfo`
once they have added it. Do not guess or invent the missing details — that is exactly
what this prestep exists to avoid. If the issue is reasonably complete, continue.

**Comment formatting.** Every comment you pass (here, in `no-weight`, and in `accept`) is
rendered as GitLab-Flavored Markdown, so compose it with **real line breaks** — pass an
argument that actually contains newlines, not one long run-on line. List the missing
items as a markdown bullet list, one per line. For example, the `mark-needs-info` comment
should look like:

```
Automated Triage: This bug report does not yet have enough information to reproduce or size it. Please add:

- Steps to reproduce.
- What actually happens — the exact error message, stack trace, or wrong behaviour.
- The expected behaviour.
- The affected version (and Drupal/PHP version if relevant).
```

(A blank line before the list, and each item on its own line, are what produce the row
breaks. The `why::needsInfo`-removal note is appended for you on its own paragraph.)

## Weight rubric

Estimate **time, not complexity** — how long the work takes a senior developer who knows
this codebase, in working hours (8-hour day). The weight is an **upper bound**: weight
`8` means "expected to take less than 8 hours". Factor in tests and docs when needed, but
do not inflate just because the problem sounds hard.

| Size | Weight | Means | Criteria |
|------|--------|-------|----------|
| XXS | 1  | < 1 hour     | A typo, a label, or spacing in the UI. A trivial one-line change. No tests or docs needed. |
| XS  | 2  | < 2 hours    | A small, localized bugfix or config change with an obvious cause. One file, maybe one test. |
| S   | 4  | < 4 hours    | A new button on a form, or a small self-contained UI tweak. Maybe a test and a line of docs. |
| M   | 8  | < 8 hours (1 day)   | A simple API endpoint — about a day of hands-on work. Usually needs docs and tests. |
| L   | 16 | < 16 hours (2 days)  | A full user profile page with backend integration — ~two days including tests. |
| XL  | 32 | < 32 hours (4 days)  | A feature spanning several components, ~four days end to end. Often worth splitting. |
| XXL | 64 | < 64 hours (> a week) | An architectural rewrite — over a week even for a senior. Split into meta/discussion issues. Very rare. |

When an issue sits between two sizes, prefer the larger and say why in the comment.

**Over 16 is a gate.** An `XL` (32) or `XXL` (64) is too large to accept automatically.
When you set such a weight, the `weight` command tags the project managers, sets
`why::needsInfo`, and the issue is **not** accepted — it waits for the project managers
to review and remove the label. Stop after setting the weight in that case.

If the issue lacks enough information to size (no clear scope, missing reproduction, an
open question that changes the answer), use `no-weight` instead of guessing.

**Where the weight reasoning goes.** Do not post a separate estimation comment — the
`weight` command no longer takes one. Explain the size in **one sentence inside the
single `accept` comment** (alongside the category and priority sentences). For
`no-weight`, post a comment starting with `Automated Triage: ` stating that no estimate
is available and why.

## Priority rubric

Be conservative: `major` and `critical` are reserved for exactly these criteria.

| Priority | Criteria |
|----------|----------|
| `minor`    | Misspellings, minor design issues affecting very few devices, edge-case bugs, things that can easily wait. |
| `normal`   | Almost all issues. **Security note:** if someone has filed a security issue publicly, still set `normal` so as not to raise suspicion. |
| `major`    | Failure of a submodule/subsystem affecting most users of it on a released version. Set carefully. |
| `critical` | White screen of death, killing other modules, or data loss. All hands on deck — needs a release now. |

When in doubt between two priorities, choose the **lower** one. A publicly filed security
issue must **never** be elevated, regardless of apparent severity — set it to `normal`.

## Category rubric

| Category   | Criteria |
|------------|----------|
| `bug`      | Current code doesn't work, testing broke because of internals, or the documentation is wrong. |
| `feature`  | Any new type of feature to the project. |
| `meta`     | A meta issue that does nothing by itself but groups many related issues. Usually has "Meta" in its name. |
| `plan`     | A discussion or planning issue with no concrete targets yet. |
| `support`  | A question that needs an answer on how to use something. |
| `task`     | Work for a patch release that is not a bug — docs, fixing tests after dependency changes, chores. |

When an issue could fit more than one, pick its **primary** intent: a "Meta" tracker is
`meta` even if its children are bugs; a planning discussion is `plan` even if it will
later spawn features.
