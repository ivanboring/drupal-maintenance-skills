---
name: suggest-solution
description: Use when asked to suggest, propose, or draft a solution/fix for accepted bug issues on GitLab. Finds open issues that are state::accepted, category::bug, weighted 1–8, not yet automation::suggestionExists, and without an open or merged merge request, downloads the project's code at the relevant version as a zip archive, reads it (read-only — never edited, no git), writes the fix as an up-to-4-paragraph comment, and sets automation::suggestionExists. Only on projects configured in config/config.php.
# Read-only enforcement: while this skill is active, file-editing tools and git
# are removed from Claude's tool pool, so the code snapshot cannot be modified
# and no git command can run. (This is enforced by the harness for the duration
# of the skill, not just by the prose below — see "Code access".)
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

# Suggest Solution

For a small, already-triaged bug, read the project's code and write up a proposed fix as
an issue comment. A candidate issue is one that is:

- open,
- `state::accepted`,
- `category::bug`,
- weighted **1, 2, 4, or 8** (the small, automatable bugs),
- **not** already labelled `automation::suggestionExists`, and
- **without an open or merged merge request** — an existing MR means the fix is already
  in progress or done, so a suggestion would be redundant. (A closed, abandoned MR does
  not disqualify the issue.)

For each such issue: download the project's code at the relevant version, understand the
bug, find a fix in the code, and post a suggestion of **up to four paragraphs**. Posting
the suggestion also sets `automation::suggestionExists`, so an issue is never suggested
twice and the skill is safe to re-run.

**If the code for the relevant version cannot be downloaded, STOP for that issue** — no
suggestion can be made without the code.

## Code access — read this first

The code is obtained by **downloading a zip archive** of a chosen version (branch or tag)
and unzipping it under `projects/`. There is **no git checkout and no remote**, so nothing
can ever be pushed. The extracted snapshot is **strictly read-only** — and this is
**enforced**, not just requested: the skill's frontmatter lists `Edit`, `Write`,
`NotebookEdit`, and `Bash(git *)` under `disallowed-tools`, so for as long as the skill is
active the harness removes the file-editing tools from the pool and blocks every `git`
command. (The restriction is scoped to the skill's run, so always invoke it via
`/suggest-solution` and let it finish in that turn.)

- ✅ **Read** files — read whatever you need to understand the bug and design the fix.
- ✅ **Fetch a version** — use `suggest.php fetch <issue-url> [ref]` to download the code at
  a specific branch or tag. To inspect a different version, just `fetch` it again with a
  different ref.
- ❌ **Never write anything** in the extracted code — no editing, creating, or deleting
  files; no patches, no formatting, no scratch files. The output is a comment, nothing
  else.
- ❌ **Never run git** — the code is a plain archive snapshot, not a repository. There is
  nothing to commit, push, branch, or otherwise mutate.

If finding the fix would require changing the code, do not — describe the change in the
comment instead.

### Choosing the version

The version to fetch is the one the issue is about: a version named in a `version` label,
the issue's milestone/target, or stated in the description. **If the issue does not specify
a version, omit the ref and the configured `default_version` for that project is used**
(set per project in `config/config.php`). Pass an explicit ref to `fetch` only when the
issue points at a particular branch or tag.

The ref can be **any branch or tag**, not just a release version — `fetch` resolves it
against the repository. When the bug is about something that lives outside the normal
release branches, fetch the branch that actually holds it. For example, a documentation
deployment / redirect bug lives on the `gh-pages` branch (the built docs and mike's
`versions.json`), so fetch that:

```
php skills/suggest-solution/scripts/suggest.php fetch <issue-url> gh-pages
```

## Security model — the GitLab side

Every GitLab request (issue reads, the archive download, the comment, the label) goes
through `lib/gitlab.php`, which talks to the GitLab REST API v4 directly with the
per-project token. There is **no `glab` and no `jq`**. A command runs **only** when:

- the issue/project resolves to a project key in **`config/config.php`**, and
- that project has a **non-empty token**.

Anything else is **hard-refused** before any network call. The archive download only
**reads** the remote repository; the only **writes** this skill makes on GitLab are the
suggestion comment and the `automation::suggestionExists` label. To set up, copy
`config/config.example.php` to `config/config.php` and add a token (and a
`default_version`) per project.

## Input

Either of two things:

- A **single GitLab issue URL** — suggest a solution for that one issue (if it qualifies).
- A **project URL or path** — find every candidate issue and suggest a solution for each.
  See *Bulk mode* below.

A single issue URL looks like:

```
https://git.drupalcode.org/group/project/-/issues/42
https://git.drupalcode.org/group/project/-/work_items/42
```

A bare issue number is not enough — issue numbers repeat across projects. The URL carries
the project. If the user gives only a number, ask for the full issue URL.

## Tool

One script does all the GitLab + download + unzip work — a subcommand CLI. Run it with PHP
from the repository root:

```
php skills/suggest-solution/scripts/suggest.php <command> ...
```

| Command | Purpose |
|---|---|
| `list-candidates <project>` | Open issues that are `state::accepted`, `category::bug`, weighted 1–8, **not** `automation::suggestionExists`, and with **no open/merged merge request** (one web URL per line; SUMMARY on stderr). |
| `show <issue-url>` | Title, state, labels, weight, category, triage state, description, and comments — enough to understand the bug. |
| `fetch <issue-url-or-project> [ref]` | Download the project's code at `[ref]` (a branch or tag) and unzip it under `projects/<module>-<ref>`; prints that directory. Omitting `[ref]` uses the project's `default_version`. **Exits non-zero (`FAILED`/`REFUSED`) when the version can't be downloaded — the signal to STOP.** |
| `suggest <issue-url> <comment> [--force]` | Post the `<comment>` (your up-to-4-paragraph write-up) and add `automation::suggestionExists`. Refuses unless the issue is `state::accepted` + `category::bug` + weight 1–8, not already suggested, and has no open/merged merge request. |
| `labels-ensure <project> [--create]` | Check (or with `--create`, create) the `automation::suggestionExists` label. |

The script prints `OK:` on success and `REFUSED:`/`FAILED:` (non-zero exit) otherwise.
Relay those plainly and do not retry blindly. Exit codes: 0 ok, 2 usage, 3 refused,
4 remote failure.

### Overwriting with `--force`

By default `suggest` refuses if the issue does not match every criterion (or already
carries `automation::suggestionExists`). There is **one exception**: when the user
**explicitly names a single issue** and asks to (re)write its suggestion, pass `--force`
to post again. `--force` is **only** for a single, explicitly identified issue — **never**
in bulk mode or across multiple issues.

## Procedure (single issue)

1. **Read the issue.** Run `suggest.php show <issue-url>` to read the title, description,
   labels, weight, category, and discussion. Confirm it is genuinely a candidate (the
   `suggest` command enforces this too, and refuses otherwise). Note any version the
   issue targets.
2. **Fetch the code.** Run `suggest.php fetch <issue-url> [ref]` — pass the version the
   issue names, or omit it to use the project's `default_version`. If it prints a
   directory, that is the extracted code. **If it `FAILED`/`REFUSED`, STOP** — report
   that the code is not available and that no suggestion was made.
3. **Find the fix in the code.** Read the relevant files in the extracted directory
   (strictly read-only — see *Code access* above). Identify the root cause and the
   concrete change that would fix it.
4. **Write the suggestion.** Run `suggest.php suggest <issue-url> "<comment>"` with **up
   to four paragraphs**. This posts the comment and sets `automation::suggestionExists`.
   See *Writing the suggestion* below for what the comment should contain.
5. **Report** the outcome: suggested / skipped (code unavailable) / not a candidate / any
   `REFUSED`/`FAILED`.

## Bulk mode (whole project)

1. **Ensure the label exists.** Run `suggest.php labels-ensure <project>`. If it reports
   `MISSING`, **ask the user to confirm** before creating it, then run
   `suggest.php labels-ensure <project> --create`. Never pass `--create` without that
   confirmation.
2. **List the work.** Run `suggest.php list-candidates <project>` to get every open
   candidate issue. Relay the summary count.
3. **Suggest for each one** with the full single-issue procedure above — `show` to read
   it and pick the version, `fetch` (skip the issue if the code cannot be downloaded),
   read the code, and `suggest`. You can reuse an already-fetched version directory across
   issues that target the same version instead of re-fetching.
4. **Report a tally:** how many issues got a suggestion, how many were skipped for
   unavailable code, and any `REFUSED`/`FAILED`.

## Writing the suggestion

Keep it to **at most four paragraphs**, addressed to the maintainer who will implement it.
A good suggestion covers, in roughly this order:

1. **Root cause** — what is actually going wrong, in terms of the code (not just the
   symptom).
2. **Where** — the specific file(s) and function(s) involved. Reference them as
   `path/to/file.php` and name the function; quote a short snippet only if it clarifies.
3. **The proposed change** — concretely what to change and why it fixes the bug. Describe
   the change in prose; do **not** write it into the code.
4. **Caveats** — anything to watch for: edge cases, tests that should be added or updated,
   backward-compatibility or version concerns, or uncertainty if you could not fully
   confirm the cause.

Start the comment with `Automated Suggestion:` so it is clearly machine-generated, and
make clear it is a proposal for a maintainer to review — not a verified, tested fix. Say
which version you read (the ref you fetched). The `suggest` command automatically appends
the italic line _"This suggestion was made by AI, so please use this suggestion with
caution"_ as the last line of every comment, so do **not** write that disclaimer yourself.

**Comment formatting.** The comment is rendered as GitLab-Flavored Markdown, so compose it
with **real line breaks** — pass an argument that actually contains newlines (a blank line
between paragraphs), not one long run-on line. Use a markdown bullet list where you are
listing several files or steps.

If, after reading the code, you cannot identify a credible fix (the cause is unclear, the
relevant code is missing, or it would need changes well beyond the estimate), **do not
guess**. Skip the issue and report why — do not post a low-confidence suggestion.
