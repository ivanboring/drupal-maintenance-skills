# Some skills

Just some skills being tested. Read this readme for how to use them.

## Installation

Use Vercel's skill command — in the terminal, run:

```bash
npx skills add ivanboring/drupal-maintenance-skills
```

## Requirements

* **PHP 8 CLI** with the `curl` and `json` extensions (both are standard). See https://www.php.net/
* A **GitLab access token** (scope `api`) for each project you want to triage.
* Preferably, the place this skill runs has a checkout of the issue's repository, so it
  can read the actual code to give a better estimation.

There is no longer any dependency on `glab` or `jq` — all GitLab work is done by PHP
talking to the GitLab REST API v4 directly.

## Configuration

All commands run **only** against projects listed in `config/config.php`, and **only**
when that project has a non-empty token. Anything else is hard-refused before any request
is made.

1. Copy the example and edit it:

   ```bash
   cp config/config.example.php config/config.php
   ```

2. Add a project key (its web URL) and a token for each project:

   ```php
   $config = [
     'https://git.drupalcode.org/project/ai' => [
       'token' => 'glpat-xxxxxxxxxxxxxxxxxxxx',
       'developers_to_tag' => ['Marcus_Johansson', 'a.dmitriiev'],
       'project_managers_to_tag' => ['arianraeesi'],
     ],
   ];
   ```

`config/config.php` is git-ignored so your tokens never get committed; only
`config/config.example.php` is tracked.

## Available Skill

### triage-issue

Triages a GitLab issue end to end. It first runs two gating presteps:

* **Duplicate check** — searches the open queue; if the issue duplicates another, it
  comments (tagging the project managers), closes it, and sets `why::duplicate` +
  `state::closed`.
* **Information check** — compares the issue against its template; if required
  information is missing, it sets `why::needsInfo` and comments what is needed.

Only if both presteps pass does it read the issue and decide and record three things
against fixed rubrics:

* **Weight** — a t-shirt size (`1`–`64`) written as the issue's numeric weight, with a
  short explanatory comment. Issues that cannot be sized get a `No Estimation Available`
  label instead.
* **Priority** — a scoped `priority::{minor|normal|major|critical}` label. Publicly filed
  security issues are deliberately kept at `normal` so as not to signal that a report is
  real and exploitable.
* **Category** — a scoped `category::{bug|feature|meta|plan|support|task}` label.

Once all three are decided, the issue is marked **`state::accepted`**. Nothing that is
already set is ever overwritten, so the skill is safe to re-run and perfect for
automation. In bulk mode it works through every open issue that has no `state::` label
yet.

#### Usage

After installing the skill and creating `config/config.php`, in your coding agent:

```bash
# A single issue:
/triage-issue https://git.drupalcode.org/project/ai/-/work_items/3577170

# Or a whole project (every open issue with no state:: label):
/triage-issue https://git.drupalcode.org/project/ai
```

#### Under the hood

The skill is a thin SKILL.md plus one PHP CLI:

```
config/config.example.php   # template (copy to config/config.php)
lib/gitlab.php              # config load + project allowlist + REST API v4 client
skills/triage-issue/
  SKILL.md                  # the rubrics and procedure the agent follows
  scripts/triage.php        # the only command: list-pre-triage|show|search|
                            # templates|mark-duplicate|mark-needs-info|weight|
                            # no-weight|priority|category|accept|labels-ensure
```
