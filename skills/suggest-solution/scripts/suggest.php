#!/usr/bin/env php
<?php

/**
 * suggest.php — the only CLI for the suggest-solution skill.
 *
 * All GitLab work goes through lib/gitlab.php, which loads config/config.php,
 * enforces the project allowlist (hard refuse), and talks to the GitLab REST
 * API v4 with the per-project token. There is no glab and no jq.
 *
 * This skill proposes a fix for a small, already-accepted bug by reading the
 * project's code at a specific version and writing the suggestion as an issue
 * comment. The code is obtained by DOWNLOADING a zip archive of the chosen
 * version (branch or tag) and unzipping it under projects/ — there is no git
 * checkout and no remote, so nothing can ever be pushed. The script does the
 * GitLab + download + unzip work; the agent reads the code and writes the
 * suggestion.
 *
 * CODE ACCESS — the extracted archive under projects/ is a read-only snapshot.
 * The agent reads it to understand the bug and design the fix; it must NEVER
 * edit, create, or delete files there and never run git at all. The only writes
 * this script makes are: the downloaded archive + its extraction under
 * projects/ (local scratch), and — on GitLab, via the allowlisted token — the
 * suggestion comment and the automation::suggestionExists label.
 *
 * Subcommands (run from the repository root):
 *
 *   list-candidates <project-url-or-path>
 *                List OPEN issues that are state::accepted, category::bug,
 *                weight in {1,2,4,8}, NOT already automation::suggestionExists,
 *                and with NO open/merged merge request (an existing MR means the
 *                fix is already in progress or done, so a suggestion would be
 *                redundant). One web URL per line on stdout; a SUMMARY on stderr.
 *
 *   show         <issue-url>
 *                Print title, state, labels, weight, category, the triage state,
 *                description, and comments — enough to understand the bug.
 *
 *   fetch        <issue-url-or-project> [ref]
 *                Download the project's repository archive at [ref] (a branch or
 *                tag — the version the issue concerns) and unzip it under
 *                projects/<module>-<ref>; print that directory. When [ref] is
 *                omitted, the project's configured default_version is used. Exits
 *                non-zero (FAILED/REFUSED) if the version cannot be downloaded —
 *                the signal to STOP for that issue.
 *
 *   suggest      <issue-url> <comment> [--force]
 *                Post <comment> (the agent's up-to-4-paragraph write-up) and add
 *                automation::suggestionExists. Refuses unless the issue is
 *                state::accepted + category::bug + weight in {1,2,4,8}, is not
 *                already labelled automation::suggestionExists, and has no
 *                open/merged merge request. --force overrides every guard for a
 *                single, explicitly named issue (never in bulk).
 *
 *   labels-ensure <project-url-or-path> [--create]
 *                Check (or with --create, create) the automation::suggestionExists
 *                label this skill uses.
 *
 * Exit codes: 0 ok, 2 usage/bad input, 3 refused, 4 remote failure.
 */

declare(strict_types=1);

require __DIR__ . '/../../../lib/gitlab.php';

const SUGGEST_LABEL    = 'automation::suggestionExists';
const SUGGEST_COLOR    = '#6f42c1';

// Appended to every posted suggestion so the reader always sees that it is
// machine-generated, regardless of what the write-up itself says.
const AI_DISCLAIMER    = '*This suggestion was made by AI, so please use this suggestion with caution*';

const ACCEPTED_STATE   = 'accepted';
const BUG_CATEGORY     = 'bug';

// "Estimation under 8" includes weight 8 (M): the small, automatable bugs.
const ELIGIBLE_WEIGHTS = [1, 2, 4, 8];

/** Every label this skill manages, name => color. */
function managed_labels(): array {
  return [SUGGEST_LABEL => SUGGEST_COLOR];
}

/** True when the issue's stored weight is a real estimate in the eligible range. */
function eligible_weight(array $issue): bool {
  $w = $issue['weight'] ?? null;
  return is_int($w) && in_array($w, ELIGIBLE_WEIGHTS, true);
}

/**
 * Does the issue match every selection criterion for a suggestion?
 * Returns a human-readable reason it does NOT qualify, or null if it qualifies.
 */
function disqualifier(array $issue): ?string {
  if (($issue['state'] ?? '') === 'closed') {
    return 'the issue is closed';
  }
  if (GitLab::scopedValue($issue, 'state') !== ACCEPTED_STATE) {
    $cur = GitLab::scopedValue($issue, 'state') ?: 'none';
    return "it is not state::" . ACCEPTED_STATE . " (state::$cur)";
  }
  if (GitLab::scopedValue($issue, 'category') !== BUG_CATEGORY) {
    $cur = GitLab::scopedValue($issue, 'category') ?: 'none';
    return "it is not category::" . BUG_CATEGORY . " (category::$cur)";
  }
  if (!eligible_weight($issue)) {
    $w = $issue['weight'] ?? null;
    $shown = (is_int($w) && $w > 0) ? (string) $w : 'none';
    return "its weight ($shown) is not in {" . implode(',', ELIGIBLE_WEIGHTS) . "}";
  }
  if (GitLab::hasLabel($issue, SUGGEST_LABEL)) {
    return 'it already has ' . SUGGEST_LABEL;
  }
  return null;
}

/**
 * The first related merge request that means work already exists for the issue —
 * an MR that is open (someone is fixing it) or merged (already fixed). A closed,
 * abandoned MR does NOT count, so a fresh suggestion is still useful there.
 * Returns the MR array, or null when nothing blocks a suggestion.
 *
 * @param array<int,array> $mrs as returned by GitLab::relatedMergeRequests()
 */
function blocking_merge_request(array $mrs): ?array {
  foreach ($mrs as $mr) {
    $state = is_array($mr) ? (string) ($mr['state'] ?? '') : '';
    if ($state === 'opened' || $state === 'merged') {
      return $mr;
    }
  }
  return null;
}

/** Append the AI disclaimer as a final italic line, unless it is already there. */
function with_disclaimer(string $comment): string {
  if (str_contains($comment, 'This suggestion was made by AI')) {
    return $comment;
  }
  return rtrim($comment) . "\n\n" . AI_DISCLAIMER;
}

/** The absolute path to the repository's projects/ scratch directory. */
function projects_dir(): string {
  return dirname(__DIR__, 3) . '/projects';
}

/** Recursively delete a file or directory. No-op if it does not exist. */
function rrmdir(string $path): void {
  if (is_link($path) || is_file($path)) {
    @unlink($path);
    return;
  }
  if (!is_dir($path)) {
    return;
  }
  foreach (scandir($path) ?: [] as $entry) {
    if ($entry === '.' || $entry === '..') {
      continue;
    }
    rrmdir($path . '/' . $entry);
  }
  @rmdir($path);
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
// --force lets `suggest` overwrite the guard on a single, explicitly named
// issue (see SKILL.md); never use it in bulk.
$force = isset($flags['--force']);

try {
  switch ($cmd) {

    case 'list-candidates': {
      $gl = GitLab::fromProject(need($pos, 0, 'project-url-or-path'));
      $issues = $gl->listOpenIssues();
      $emitted = 0;
      $withMr = 0;
      foreach ($issues as $issue) {
        if (disqualifier($issue) !== null) {
          continue;
        }
        // An issue that already has an open/merged MR is being (or has been)
        // fixed, so a suggestion would be redundant — skip it. Only checked for
        // issues that pass the cheap label/weight filter, to limit API calls.
        $iid = isset($issue['iid']) ? (int) $issue['iid'] : null;
        if ($iid !== null && blocking_merge_request($gl->relatedMergeRequests($iid)) !== null) {
          $withMr++;
          continue;
        }
        echo ($issue['web_url'] ?? '') . "\n";
        $emitted++;
      }
      fwrite(STDERR, "SUMMARY: $emitted of " . count($issues)
        . " open issue(s) are ready for a suggestion (state::" . ACCEPTED_STATE
        . ", category::" . BUG_CATEGORY . ", weight in {" . implode(',', ELIGIBLE_WEIGHTS)
        . "}, not " . SUGGEST_LABEL . ", no open/merged merge request"
        . ($withMr > 0 ? "; $withMr otherwise-eligible issue(s) skipped for an existing merge request" : "")
        . ").\n");
      break;
    }

    case 'show': {
      $gl = GitLab::fromIssue(need($pos, 0, 'issue-url'));
      $issue = $gl->getIssue();

      $weight = $issue['weight'] ?? null;
      $weightOut = (is_int($weight) && $weight > 0) ? (string) $weight : 'none';
      $category = GitLab::scopedValue($issue, 'category') ?: 'none';
      $state    = GitLab::scopedValue($issue, 'state') ?: 'none';

      printf("TITLE: %s\n", $issue['title'] ?? '');
      printf("STATE: %s\n", $issue['state'] ?? '');
      printf("LABELS: %s\n", implode(', ', GitLab::labelNames($issue)));
      printf("WEIGHT: %s\n", $weightOut);
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

    case 'fetch': {
      $gl = GitLab::fromProject(need($pos, 0, 'issue-url-or-project'));

      // The version (ref) is whatever the issue targets; fall back to the
      // project's configured default_version when none is given.
      $ref = $pos[1] ?? '';
      if ($ref === '') {
        $ref = (string) ($gl->settings()['default_version'] ?? '');
        if ($ref === '') {
          fail("REFUSED: no version given and no default_version configured for "
            . $gl->projectPath() . " in config/config.php.", 3);
        }
        fwrite(STDERR, "NOTE: no version specified; using configured default_version '$ref'.\n");
      }

      $projectsDir = projects_dir();
      if (!is_dir($projectsDir) && !@mkdir($projectsDir, 0775, true)) {
        fail("FAILED: cannot create the projects/ directory.", 4);
      }

      $base = basename($gl->projectPath());
      $safeRef = preg_replace('/[^A-Za-z0-9._-]+/', '-', $ref);
      $dest = $projectsDir . '/' . $base . '-' . $safeRef;
      $zipPath = $projectsDir . '/.archive-' . $base . '-' . $safeRef . '.zip';
      $tmp = $projectsDir . '/.extract-' . $base . '-' . $safeRef;

      // Start clean so a re-fetch always reflects the current remote.
      rrmdir($dest);
      rrmdir($tmp);
      @unlink($zipPath);

      $gl->downloadArchive($ref, $zipPath);

      if (!@mkdir($tmp, 0775, true)) {
        @unlink($zipPath);
        fail("FAILED: cannot create a temporary extraction directory.", 4);
      }
      try {
        // PHP's bundled Phar reads zip archives, so no unzip binary / ext-zip
        // is needed. extractTo(..., overwrite=true).
        (new PharData($zipPath))->extractTo($tmp, null, true);
      } catch (Throwable $e) {
        @unlink($zipPath);
        rrmdir($tmp);
        fail("FAILED: could not unzip the archive: " . $e->getMessage(), 4);
      }
      @unlink($zipPath);

      // GitLab archives extract to a single top-level directory
      // (e.g. "ai-1.x-<sha>"); move it to the stable $dest path.
      $inner = null;
      foreach (scandir($tmp) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
          continue;
        }
        if (is_dir($tmp . '/' . $entry)) {
          $inner = $tmp . '/' . $entry;
          break;
        }
      }
      if ($inner === null || !@rename($inner, $dest)) {
        rrmdir($tmp);
        fail("FAILED: unexpected archive layout; could not place the extracted code.", 4);
      }
      rrmdir($tmp);

      echo $dest . "\n";
      fwrite(STDERR, "OK: fetched " . $gl->projectPath() . " at '$ref' into projects/.\n");
      break;
    }

    case 'suggest': {
      $gl = GitLab::fromIssue(need($pos, 0, 'issue-url'));
      $comment = with_disclaimer(need($pos, 1, 'comment'));
      $issue = $gl->getIssue();

      if (!$force && ($why = disqualifier($issue))) {
        $extra = GitLab::hasLabel($issue, SUGGEST_LABEL)
          ? ''
          : ' Pass --force to override for a single, explicitly named issue.';
        fail("REFUSED: cannot suggest — $why.$extra", 3);
      }
      // A fix is already in progress / done when an open or merged MR is linked;
      // do not suggest over it unless the user explicitly forces a single issue.
      if (!$force && ($mr = blocking_merge_request($gl->relatedMergeRequests())) !== null) {
        $url = is_array($mr) ? (string) ($mr['web_url'] ?? '') : '';
        $state = is_array($mr) ? (string) ($mr['state'] ?? '') : '';
        $which = $state === 'merged' ? 'a merged' : 'an open';
        fail("REFUSED: cannot suggest — the issue already has $which merge request"
          . ($url !== '' ? " ($url)" : '')
          . '. Pass --force to override for a single, explicitly named issue.', 3);
      }
      // Re-suggesting with --force must not stack a second label.
      if ($force && GitLab::hasLabel($issue, SUGGEST_LABEL)) {
        // The label is already present; just refresh the comment below.
        $gl->addComment($comment);
        echo "OK: posted suggestion (label " . SUGGEST_LABEL . " already present).\n";
        break;
      }
      $gl->addComment($comment);
      $gl->addLabels([SUGGEST_LABEL]);
      echo "OK: posted suggestion; set " . SUGGEST_LABEL . "\n";
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
      fwrite(STDERR, "usage: suggest.php <list-candidates|show|fetch|suggest|labels-ensure> ...\n");
      exit(2);

    default:
      fail("usage error: unknown command '$cmd'.", 2);
  }
} catch (TriageError $e) {
  $msg = $e->getMessage();
  $code = str_starts_with($msg, 'REFUSED') ? 3 : (str_starts_with($msg, 'FAILED') ? 4 : 2);
  fail($msg, $code);
}
