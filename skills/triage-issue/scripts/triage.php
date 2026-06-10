#!/usr/bin/env php
<?php

/**
 * triage.php — the only CLI for the triage-issue skill.
 *
 * All GitLab work goes through lib/gitlab.php, which loads config/config.php,
 * enforces the project allowlist (hard refuse), and talks to the GitLab REST
 * API v4 with the per-project token. There is no glab and no jq.
 *
 * Subcommands (run from the repository root):
 *
 *   list-pre-triage <project-url-or-path>
 *                List OPEN issues that have no state:: label yet AND are not
 *                labelled why::needsInfo (one web URL per line on stdout; a
 *                SUMMARY count on stderr).
 *
 *   show         <issue-url>
 *                Print title, state, labels, weight, priority, category, the
 *                triage state, description, and comments.
 *
 *   search       <project-url-or-path> <query>
 *                Full-text search the open issue queue (title + description).
 *                One match per line: "state<TAB>web_url<TAB>title". Used by the
 *                duplicate prestep.
 *
 *   templates    <project-url-or-path> [name]
 *                With no name, list the project's issue template keys. With a
 *                name, print that template's content. Used by the info prestep.
 *
 *   mark-duplicate <issue-url> <duplicate-url-or-ref> [comment] [--force]
 *                Comment that the issue duplicates <duplicate-url-or-ref> (naming the
 *                configured project managers WITHOUT "@", so they are not pinged — the
 *                reporter can tag them if they disagree), add why::duplicate +
 *                state::closed, and close the issue. Refuses if already closed / stated.
 *
 *   mark-needs-info <issue-url> <comment> [--force]
 *                Add why::needsInfo and post <comment> (what is missing). Refuses
 *                if already marked why::needsInfo.
 *
 *   weight       <issue-url> <weight> <comment> [--force]
 *                Set the issue weight (1|2|4|8|16|32|64) and post the comment.
 *                Refuses if already weighted or marked "No Estimation Available".
 *                A weight over 16 is auto-flagged for project-manager review:
 *                it tags the project managers and sets why::needsInfo (do not accept).
 *
 *   no-weight    <issue-url> <comment> [--force]
 *                Mark the issue "No Estimation Available" and post the comment.
 *                Refuses if already weighted.
 *
 *   priority     <issue-url> <minor|normal|major|critical> [--force]
 *                Set priority::<value>. Refuses if a priority:: label exists.
 *
 *   category     <issue-url> <bug|feature|meta|plan|support|task> [--force]
 *                Set category::<value>. Refuses if a category:: label exists.
 *
 *   accept       <issue-url> <comment>
 *                Post <comment> (one sentence on why the category was set, one on
 *                why the priority was set) and set state::accepted — but ONLY once
 *                weight, priority, and category are all decided. Refuses otherwise,
 *                or if a state:: label already exists. If the priority is major or
 *                critical, the developer maintainers are tagged in the comment.
 *
 *   labels-ensure <project-url-or-path> [--create]
 *                Check (or with --create, create) every label the skill uses.
 *
 * --force overrides the "never overwrite" guard on a setter. It is only ever
 * valid for a single, explicitly named issue — never in bulk. See SKILL.md.
 *
 * Exit codes: 0 ok, 2 usage/bad input, 3 refused, 4 remote failure.
 */

declare(strict_types=1);

require __DIR__ . '/../../../lib/gitlab.php';

const PRIORITY_COLOR = '#ff5353';
const CATEGORY_COLOR = '#1f78d1';
const STATE_COLOR    = '#2da160';
const CLOSED_COLOR   = '#6c757d';
const WHY_COLOR      = '#d9772b';
const NO_EST_COLOR   = '#cd5b45';
const NO_EST_LABEL   = 'No Estimation Available';

const DUPLICATE_LABEL  = 'why::duplicate';
const NEEDS_INFO_LABEL = 'why::needsInfo';
const CLOSED_LABEL     = 'state::closed';

const PRIORITIES = ['minor', 'normal', 'major', 'critical'];
const CATEGORIES = ['bug', 'feature', 'meta', 'plan', 'support', 'task'];
const WEIGHTS    = [1, 2, 4, 8, 16, 32, 64];

// A weight strictly above this is too big to auto-accept: it is flagged for
// project-manager review (PM-tagged comment + why::needsInfo) instead.
const HIGH_EFFORT_THRESHOLD = 16;

/** Every label this skill manages, name => color. */
function managed_labels(): array {
  $labels = [];
  foreach (PRIORITIES as $p) { $labels["priority::$p"] = PRIORITY_COLOR; }
  foreach (CATEGORIES as $c) { $labels["category::$c"] = CATEGORY_COLOR; }
  $labels['state::accepted'] = STATE_COLOR;
  $labels[CLOSED_LABEL]      = CLOSED_COLOR;
  $labels[DUPLICATE_LABEL]   = WHY_COLOR;
  $labels[NEEDS_INFO_LABEL]  = WHY_COLOR;
  $labels[NO_EST_LABEL]      = NO_EST_COLOR;
  return $labels;
}

/**
 * The presteps are gates: a duplicate or needs-info issue must not be estimated
 * or accepted. Returns a human-readable reason if the issue is gated, else null.
 */
function gating_block(array $issue): ?string {
  if (($issue['state'] ?? '') === 'closed') {
    return 'the issue is closed';
  }
  if (GitLab::hasLabel($issue, DUPLICATE_LABEL)) {
    return 'it is marked ' . DUPLICATE_LABEL . ' (duplicate prestep)';
  }
  if (GitLab::hasLabel($issue, NEEDS_INFO_LABEL)) {
    return 'it is marked ' . NEEDS_INFO_LABEL . ' (information prestep)';
  }
  return null;
}

/** A weight is "decided" when it is a real estimate or explicitly waived. */
function weight_decided(array $issue): bool {
  $w = $issue['weight'] ?? null;
  if (is_int($w) && $w > 0) {
    return true; // stored 0 is the drupal.org migration default, not an estimate.
  }
  return GitLab::hasLabel($issue, NO_EST_LABEL);
}

function fail(string $message, int $code = 2): never {
  fwrite(STDERR, $message . "\n");
  exit($code);
}

function need(array $pos, int $i, string $what): string {
  if (!isset($pos[$i]) || $pos[$i] === '') {
    fail("usage error: missing <$what>", 2);
  }
  return $pos[$i];
}

/* ------------------------------------------------------------------------- */

$cmd = $argv[1] ?? '';

// Split the remaining args into positionals and flags (--force, --create).
$pos = [];
$flags = [];
foreach (array_slice($argv, 2) as $arg) {
  if (str_starts_with($arg, '--')) {
    $flags[$arg] = true;
  } else {
    $pos[] = $arg;
  }
}
// --force lets a setter overwrite a value that is already set. It is ONLY valid
// for a single, explicitly named issue (see SKILL.md); never use it in bulk.
$force = isset($flags['--force']);

try {
  switch ($cmd) {

    case 'list-pre-triage': {
      $gl = GitLab::fromProject(need($pos, 0, 'project-url-or-path'));
      $issues = $gl->listOpenIssues();
      $emitted = 0;
      foreach ($issues as $issue) {
        if (GitLab::scopedValue($issue, 'state') !== '') {
          continue; // already triaged
        }
        if (GitLab::hasLabel($issue, 'why::needsInfo')) {
          continue; // waiting on the reporter — skip until the info arrives
        }
        echo ($issue['web_url'] ?? '') . "\n";
        $emitted++;
      }
      fwrite(STDERR, "SUMMARY: $emitted of " . count($issues) . " open issue(s) are ready for pre-triage (no state:: label, not why::needsInfo).\n");
      break;
    }

    case 'show': {
      $gl = GitLab::fromIssue(need($pos, 0, 'issue-url'));
      $issue = $gl->getIssue();

      $weight = $issue['weight'] ?? null;
      $weightOut = (is_int($weight) && $weight > 0) ? (string) $weight : 'none';
      $priority = GitLab::scopedValue($issue, 'priority') ?: 'none';
      $category = GitLab::scopedValue($issue, 'category') ?: 'none';
      $state    = GitLab::scopedValue($issue, 'state') ?: 'none';

      printf("TITLE: %s\n", $issue['title'] ?? '');
      printf("STATE: %s\n", $issue['state'] ?? '');
      printf("LABELS: %s\n", implode(', ', GitLab::labelNames($issue)));
      printf("WEIGHT: %s\n", $weightOut);
      printf("PRIORITY: %s\n", $priority);
      printf("CATEGORY: %s\n", $category);
      printf("TRIAGE_STATE: %s\n", $state);
      printf("DESCRIPTION:\n%s\n", $issue['description'] ?? '');

      echo "COMMENTS:\n";
      foreach ($gl->getComments() as $note) {
        $author = $note['author']['username'] ?? ($note['author']['name'] ?? 'unknown');
        printf("--- %s commented %s:\n%s\n", $author, $note['created_at'] ?? '', $note['body'] ?? '');
      }
      break;
    }

    case 'search': {
      $gl = GitLab::fromProject(need($pos, 0, 'project-url-or-path'));
      $query = need($pos, 1, 'query');
      $matches = $gl->searchIssues($query);
      foreach ($matches as $m) {
        $title = str_replace(["\t", "\n", "\r"], ' ', (string) ($m['title'] ?? ''));
        printf("%s\t%s\t%s\n", $m['state'] ?? '', $m['web_url'] ?? '', $title);
      }
      fwrite(STDERR, "SUMMARY: " . count($matches) . " open issue(s) match \"$query\".\n");
      break;
    }

    case 'templates': {
      $gl = GitLab::fromProject(need($pos, 0, 'project-url-or-path'));
      $name = $pos[1] ?? '';
      if ($name === '') {
        foreach ($gl->listIssueTemplates() as $t) {
          echo (string) ($t['key'] ?? $t['name'] ?? '') . "\n";
        }
      } else {
        $t = $gl->getIssueTemplate($name);
        echo (string) ($t['content'] ?? '') . "\n";
      }
      break;
    }

    case 'mark-duplicate': {
      $gl = GitLab::fromIssue(need($pos, 0, 'issue-url'));
      $dup = need($pos, 1, 'duplicate-url-or-ref');
      $extra = $pos[2] ?? '';
      $issue = $gl->getIssue();
      if (!$force) {
        if (($issue['state'] ?? '') === 'closed') {
          fail("REFUSED: issue is already closed. Pass --force to overwrite a specific issue.", 3);
        }
        $st = GitLab::scopedValue($issue, 'state');
        if ($st !== '') {
          fail("REFUSED: issue already has state::$st. Pass --force to overwrite a specific issue.", 3);
        }
        if (GitLab::hasLabel($issue, DUPLICATE_LABEL)) {
          fail("REFUSED: issue is already marked " . DUPLICATE_LABEL . ".", 3);
        }
      }
      $pms = (array) ($gl->settings()['project_managers_to_tag'] ?? []);
      // Names WITHOUT a leading "@" so the project managers are not pinged automatically;
      // the reporter can tag them if they disagree.
      $names = implode(', ', array_map(static fn($u) => ltrim((string) $u, '@'), $pms));
      $comment = "Automated Triage: This issue appears to be a duplicate of $dup. "
        . "Please continue the discussion and work there; this issue is being closed as a duplicate.";
      if ($names !== '') {
        $comment .= " If you do not agree that this is a duplicate, please re-open and tag the project manager(s) $names.";
      }
      if ($extra !== '') {
        $comment .= "\n\n" . $extra;
      }
      $gl->addComment($comment);
      // "Status: Duplicate" = closed status with the duplicate reason.
      $gl->changeLabels([DUPLICATE_LABEL, CLOSED_LABEL]);
      $gl->closeIssue();
      echo "OK: marked duplicate of $dup; set " . DUPLICATE_LABEL . " + " . CLOSED_LABEL . "; closed issue\n";
      break;
    }

    case 'mark-needs-info': {
      $gl = GitLab::fromIssue(need($pos, 0, 'issue-url'));
      $comment = need($pos, 1, 'comment');
      $issue = $gl->getIssue();
      if (!$force && GitLab::hasLabel($issue, NEEDS_INFO_LABEL)) {
        fail("REFUSED: issue is already marked " . NEEDS_INFO_LABEL . ".", 3);
      }
      $full = $comment . "\n\nOnce you have added the missing information, please remove the `"
        . NEEDS_INFO_LABEL . "` label so the issue can be triaged.";
      $gl->addComment($full);
      $gl->addLabels([NEEDS_INFO_LABEL]);
      echo "OK: set " . NEEDS_INFO_LABEL . "\n";
      break;
    }

    case 'weight': {
      $gl = GitLab::fromIssue(need($pos, 0, 'issue-url'));
      $weight = (int) need($pos, 1, 'weight');
      $comment = need($pos, 2, 'comment');
      if (!in_array($weight, WEIGHTS, true)) {
        fail("usage error: weight must be one of " . implode('|', WEIGHTS) . "; got: $weight", 2);
      }
      $issue = $gl->getIssue();
      if (!$force && ($gate = gating_block($issue))) {
        fail("REFUSED: cannot estimate weight — $gate. Resolve the prestep first.", 3);
      }
      if (!$force && weight_decided($issue)) {
        $cur = (is_int($issue['weight'] ?? null) && $issue['weight'] > 0) ? "weight {$issue['weight']}" : NO_EST_LABEL;
        fail("REFUSED: issue weight already decided ($cur). Pass --force to overwrite a specific issue.", 3);
      }
      // Re-estimating clears any prior "No Estimation Available" waiver.
      if ($force && GitLab::hasLabel($issue, NO_EST_LABEL)) {
        $gl->changeLabels([], [NO_EST_LABEL]);
      }
      $gl->setWeight($weight);
      $gl->addComment($comment);
      if ($weight > HIGH_EFFORT_THRESHOLD) {
        // Too big to auto-accept: hand it to the project managers and gate it.
        $pms = (array) ($gl->settings()['project_managers_to_tag'] ?? []);
        $mentions = implode(' ', array_map(static fn($u) => '@' . ltrim((string) $u, '@'), $pms));
        $pmComment = "Automated Triage: This issue has been given too high an estimation (weight $weight) and will be checked by the project managers"
          . ($mentions !== '' ? " $mentions." : ".");
        $gl->addComment($pmComment);
        $gl->addLabels([NEEDS_INFO_LABEL]);
        echo "OK: set weight $weight; over " . HIGH_EFFORT_THRESHOLD
          . " — flagged for project-manager review (set " . NEEDS_INFO_LABEL . "); do not accept.\n";
      } else {
        echo "OK: set weight $weight\n";
      }
      break;
    }

    case 'no-weight': {
      $gl = GitLab::fromIssue(need($pos, 0, 'issue-url'));
      $comment = need($pos, 1, 'comment');
      $issue = $gl->getIssue();
      if (!$force) {
        if ($gate = gating_block($issue)) {
          fail("REFUSED: cannot set weight — $gate. Resolve the prestep first.", 3);
        }
        if (is_int($issue['weight'] ?? null) && $issue['weight'] > 0) {
          fail("REFUSED: issue already has weight {$issue['weight']}. Pass --force to overwrite a specific issue.", 3);
        }
        if (GitLab::hasLabel($issue, NO_EST_LABEL)) {
          fail("REFUSED: issue already marked \"" . NO_EST_LABEL . "\".", 3);
        }
      }
      $gl->addLabels([NO_EST_LABEL]);
      $gl->addComment($comment);
      echo "OK: marked \"" . NO_EST_LABEL . "\"\n";
      break;
    }

    case 'priority': {
      $gl = GitLab::fromIssue(need($pos, 0, 'issue-url'));
      $priority = need($pos, 1, 'priority');
      if (!in_array($priority, PRIORITIES, true)) {
        fail("usage error: priority must be one of " . implode('|', PRIORITIES) . "; got: $priority", 2);
      }
      $issue = $gl->getIssue();
      if (!$force && ($gate = gating_block($issue))) {
        fail("REFUSED: cannot set priority — $gate. Resolve the prestep first.", 3);
      }
      $cur = GitLab::scopedValue($issue, 'priority');
      if (!$force && $cur !== '') {
        fail("REFUSED: issue already has priority::$cur. Pass --force to overwrite a specific issue.", 3);
      }
      $remove = ($cur !== '' && $cur !== $priority) ? ["priority::$cur"] : [];
      $gl->changeLabels(["priority::$priority"], $remove);
      echo "OK: set priority::$priority\n";
      break;
    }

    case 'category': {
      $gl = GitLab::fromIssue(need($pos, 0, 'issue-url'));
      $category = need($pos, 1, 'category');
      if (!in_array($category, CATEGORIES, true)) {
        fail("usage error: category must be one of " . implode('|', CATEGORIES) . "; got: $category", 2);
      }
      $issue = $gl->getIssue();
      if (!$force && ($gate = gating_block($issue))) {
        fail("REFUSED: cannot set category — $gate. Resolve the prestep first.", 3);
      }
      $cur = GitLab::scopedValue($issue, 'category');
      if (!$force && $cur !== '') {
        fail("REFUSED: issue already has category::$cur. Pass --force to overwrite a specific issue.", 3);
      }
      $remove = ($cur !== '' && $cur !== $category) ? ["category::$cur"] : [];
      $gl->changeLabels(["category::$category"], $remove);
      echo "OK: set category::$category\n";
      break;
    }

    case 'accept': {
      $gl = GitLab::fromIssue(need($pos, 0, 'issue-url'));
      $comment = need($pos, 1, 'comment');
      $issue = $gl->getIssue();

      if ($gate = gating_block($issue)) {
        fail("REFUSED: cannot accept — $gate. Resolve the prestep first.", 3);
      }

      $cur = GitLab::scopedValue($issue, 'state');
      if ($cur !== '') {
        fail("REFUSED: issue already has state::$cur.", 3);
      }

      $priority = GitLab::scopedValue($issue, 'priority');
      $missing = [];
      if (!weight_decided($issue)) { $missing[] = 'weight'; }
      if ($priority === '') { $missing[] = 'priority'; }
      if (GitLab::scopedValue($issue, 'category') === '') { $missing[] = 'category'; }
      if ($missing !== []) {
        fail("REFUSED: cannot accept — still missing: " . implode(', ', $missing) . ".", 3);
      }

      // A major/critical issue tags the developer maintainers in the accept comment.
      if ($priority === 'major' || $priority === 'critical') {
        $devs = (array) ($gl->settings()['developers_to_tag'] ?? []);
        $mentions = implode(' ', array_map(static fn($u) => '@' . ltrim((string) $u, '@'), $devs));
        if ($mentions !== '') {
          $comment .= " As this is a $priority issue, flagging the maintainers: $mentions.";
        }
      }

      $gl->addComment($comment);
      $gl->addLabels(['state::accepted']);
      echo "OK: set state::accepted\n";
      break;
    }

    case 'labels-ensure': {
      $gl = GitLab::fromProject(need($pos, 0, 'project-url-or-path'));
      $create = isset($flags['--create']);

      $existing = [];
      foreach ($gl->listLabels() as $label) {
        $existing[(string) ($label['name'] ?? '')] = true;
      }

      $missing = [];
      foreach (managed_labels() as $name => $color) {
        if (isset($existing[$name])) {
          echo "EXISTS: $name\n";
        } else {
          echo "MISSING: $name\n";
          $missing[$name] = $color;
        }
      }

      if ($missing === []) {
        echo "OK: all managed labels already exist.\n";
        break;
      }
      if (!$create) {
        fwrite(STDERR, count($missing) . " label(s) missing. Re-run with --create to create them.\n");
        exit(3);
      }

      $failed = 0;
      foreach ($missing as $name => $color) {
        try {
          $gl->createLabel($name, $color);
          echo "CREATED: $name ($color)\n";
        } catch (TriageError $e) {
          fwrite(STDERR, "FAILED: could not create $name: " . $e->getMessage() . "\n");
          $failed++;
        }
      }
      if ($failed > 0) {
        fail("FAILED: $failed label(s) could not be created.", 4);
      }
      echo "OK: created all missing labels.\n";
      break;
    }

    case '':
    case '-h':
    case '--help':
      fwrite(STDERR, "usage: triage.php <list-pre-triage|show|search|templates|mark-duplicate|mark-needs-info|weight|no-weight|priority|category|accept|labels-ensure> ...\n");
      exit(2);

    default:
      fail("usage error: unknown command '$cmd'.", 2);
  }
} catch (TriageError $e) {
  $msg = $e->getMessage();
  $code = str_starts_with($msg, 'REFUSED') ? 3 : (str_starts_with($msg, 'FAILED') ? 4 : 2);
  fail($msg, $code);
}
