# Some skills

Just some skills being tested. Read readme how to use them.

## Installation

Just use Vercels skill command, so in the terminal, run:

```bash
npx skills add ivanboring/drupal-maintenance-skills
```

## Available Skills

### estimate-issue-weight

This commands utilizes a Gitlab API to estimate the weight of an issue based on its description and to give a comment why the issue has that weight. It was built to work with Drupal's public Gitlab, but can work with any Gitlab instance. It's secured in that it uses commands instead of directly using the `glab` command, so it's perfect for automation.

#### Requirements

* `glab` command line tool installed, globally available and authenticated with the Gitlab instance you want to use it with. See https://docs.gitlab.com/cli/
* `jq` command line tool installed, globally available. See https://jqlang.org/
* Preferably the place this skill exists has access to the repository of the issue, so it can read actual code to give a better estimation.

#### Usage

Just do the following in your coding agent after installing the skill:

```bash
/estimate-issue-weight https://git.drupalcode.org/project/ai/-/work_items/3577170
```

This example is with Drupal's public Gitlab, but you can use any Gitlab instance as long as you are authenticated and use the absolute url.

### estimate-issue-priority

This command utilizes a Gitlab API to estimate the priority of an issue based on its description and comments, evaluated against a fixed priority rubric (`minor`, `normal`, `major`, `critical`). It sets the result as a scoped `priority::{priority}` label and never overwrites a priority that already exists. It was built to work with Drupal's public Gitlab, but can work with any Gitlab instance. It's secured in that it uses commands instead of directly using the `glab` command, so it's perfect for automation. Publicly filed security issues are deliberately kept at `normal` so as not to signal that a report is real and exploitable.

#### Requirements

* `glab` command line tool installed, globally available and authenticated with the Gitlab instance you want to use it with. See https://docs.gitlab.com/cli/
* `jq` command line tool installed, globally available. See https://jqlang.org/
* Preferably the place this skill exists has access to the repository of the issue, so it can read actual code to give a better estimation.

#### Usage

Just do the following in your coding agent after installing the skill:

```bash
/estimate-issue-priority https://git.drupalcode.org/project/ai/-/work_items/3577170
```

This example is with Drupal's public Gitlab, but you can use any Gitlab instance as long as you are authenticated and use the absolute url.
