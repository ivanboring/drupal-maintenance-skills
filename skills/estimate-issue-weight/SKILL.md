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
  current weight, description, and comments.
- `scripts/glab-issue-estimate.sh <issue-url> <weight> <comment>` — sets the weight
  and posts the comment in one step. It refuses (writes nothing, non-zero exit) if a
  weight already exists.

Run them from the repository root, e.g.
`.agents/skills/estimate-issue-weight/scripts/glab-issue-show.sh <issue-url>`.

## Procedure

1. **Read the issue.** Run `glab-issue-show.sh <issue-url>`.
2. **Stop if already weighted.** If the `WEIGHT:` line is anything other than `none`,
   do not change it. Tell the user the issue already has that weight and stop.
   (A stored weight of `0` is reported as `none`: it is the drupal.org migration
   default, never a real estimate, so the skill is free to size the issue.)
3. **Evaluate the scope** against the rubric below, using the title, description, and
   comments. Weigh effort, whether tests/docs are needed, and whether architecture or
   breaking changes are involved.
4. **Pick a size and its weight** from the rubric.
5. **Write a two-sentence comment** (see rules below).
6. **Set it.** Run
   `glab-issue-estimate.sh <issue-url> <weight> "<comment>"`.
7. **Report the result** to the user: the chosen size, the weight, and the comment.
   If the script prints `REFUSED`, `FAILED`, or `PARTIAL`, relay that plainly and do
   not retry blindly.

## Sizing rubric

| Size | Weight | Criteria |
|------|--------|----------|
| XS  | 1  | Fixing a typo or spacing in the UI. Doable in under 1 hour with testing. Usually needs no test scripts or documentation — or the issue itself is fixing documentation or a test script. |
| S   | 3  | Adding a new button to a form. 1–8 hours with testing, and potentially writing test scripts and documentation. |
| M   | 5  | Creating an API endpoint. Not very complex and needs no architecture changes. Usually requires documentation and test scripts. 1–3 days. |
| L   | 8  | Building a full user profile page, including backend integration. 4–5 days. Requires architecture changes and possibly breaking changes. |
| XL  | 13 | A full feature taking over a week. Should be split into multiple issues. Requires architectural discussion. |
| XXL | 20 | An architectural rewrite taking weeks. Should be split into discussion issues, meta issues, and creation issues. Very rare — e.g. rewriting Drupal AI to use Symfony AI. |

When an issue sits between two sizes, prefer the larger one and say why in the comment.

## Comment rules

The comment must:

- be **exactly two sentences**;
- **start with** `Automated Estimation: `;
- state which size/weight was chosen and the main reason for it.

Example:

```
Automated Estimation: Sized as M (weight 5) because it adds a new API endpoint with
its own validation and tests but needs no architecture changes. The work is estimated
at one to three days including documentation.
```
