<?php

/**
 * Triage configuration.
 *
 * Copy this file to config/config.php and fill in a real token for each
 * project you want the triage skill to touch.
 *
 * SECURITY MODEL
 * --------------
 * The triage scripts will ONLY operate on projects listed here, and ONLY when
 * that project has a non-empty token. Any issue or project URL that does not
 * resolve to one of these keys is hard-refused before a single request is made.
 *
 * Each key is the project's web URL (scheme + host + full project path, no
 * trailing "/-/..." part). It identifies both the GitLab instance (host) and
 * the project (path), so a single config file can span several instances.
 */

$config = [

  // Example project. Duplicate this block for every project you want to triage.
  'https://git.drupalcode.org/project/ai' => [

    // A GitLab personal/project access token with `api` scope for THIS project.
    // Sent as the PRIVATE-TOKEN header on every request to this project.
    'token' => 'glpat-xxxxxxxxxxxxxxxxxxxx',

    // Default version (branch or tag) the suggest-solution skill fetches when an
    // issue does not name a version of its own. It is used as the ref for the
    // source archive the skill downloads to read the code, e.g. '1.x'.
    'default_version' => '1.x',

    // Maintainer usernames that may be @-tagged on issues (developer side).
    // Reserved for a later feature; not used by the current scripts.
    'developers_to_tag' => [
      'Marcus_Johansson',
      'a.dmitriiev',
    ],

    // Maintainer usernames that may be @-tagged on issues (project-manager side).
    // Reserved for a later feature; not used by the current scripts.
    'project_managers_to_tag' => [
      'arianraeesi',
      'vidit-anjaria',
    ],
  ],

];
