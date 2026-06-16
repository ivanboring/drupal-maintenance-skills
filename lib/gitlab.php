<?php

/**
 * lib/gitlab.php — shared GitLab REST client + config/allowlist for the triage skill.
 *
 * This is the ONLY place that loads config, enforces the project allowlist, and
 * talks to GitLab. Every CLI script goes through it, so the security guarantees
 * live in one file:
 *
 *   - A project is usable ONLY if it is a key in config/config.php AND has a
 *     non-empty token. Anything else is hard-refused (TriageError) before any
 *     network call.
 *   - The per-project token is sent as the PRIVATE-TOKEN header on every request;
 *     it is never read from the environment or from glab.
 *
 * No external dependencies beyond PHP's curl + json extensions. No glab, no jq.
 */

declare(strict_types=1);

/** Thrown for any usage / config / allowlist / HTTP problem. */
final class TriageError extends RuntimeException {}

final class GitLab {

  /** @var string e.g. "https://git.drupalcode.org" */
  private string $origin;
  /** @var string project path, not URL-encoded, e.g. "project/ai" */
  private string $projectPath;
  /** @var string the per-project access token */
  private string $token;
  /** @var int|null issue iid when built from an issue URL */
  public ?int $iid;

  private function __construct(string $origin, string $projectPath, string $token, ?int $iid) {
    $this->origin = $origin;
    $this->projectPath = $projectPath;
    $this->token = $token;
    $this->iid = $iid;
  }

  /* ----------------------------------------------------------------------- *
   * Construction + allowlist
   * ----------------------------------------------------------------------- */

  /** Load config/config.php and return the raw $config array. */
  public static function loadConfig(): array {
    $path = __DIR__ . '/../config/config.php';
    if (!is_file($path)) {
      throw new TriageError(
        "Missing config/config.php. Copy config/config.example.php to config/config.php and add your token(s)."
      );
    }
    $config = null;
    require $path;
    if (!is_array($config) || $config === []) {
      throw new TriageError("config/config.php must define a non-empty \$config array.");
    }
    return $config;
  }

  /**
   * Build a client from an issue URL (must contain /-/issues/ or /-/work_items/).
   * Enforces the allowlist and a non-empty token.
   */
  public static function fromIssue(string $url): self {
    [$origin, $projectPath, $iid] = self::parse($url);
    if ($iid === null) {
      throw new TriageError("Not an issue URL (needs /-/issues/<n> or /-/work_items/<n>): $url");
    }
    return self::resolve($origin, $projectPath, $iid);
  }

  /** Build a client from a project URL or path. Enforces the allowlist + token. */
  public static function fromProject(string $url): self {
    [$origin, $projectPath, $iid] = self::parse($url);
    return self::resolve($origin, $projectPath, $iid);
  }

  /**
   * Parse any project/issue URL (or bare host/path) into [origin, projectPath, iid].
   * The "/-/..." tail (issues, work_items, boards, anything) is stripped from the
   * project path; an issue iid is extracted when the tail is issues/work_items.
   */
  public static function parse(string $url): array {
    $url = trim($url);
    if ($url === '') {
      throw new TriageError("Empty URL.");
    }

    // Pull out an issue iid if present.
    $iid = null;
    foreach (['/-/issues/', '/-/work_items/'] as $marker) {
      $pos = strpos($url, $marker);
      if ($pos !== false) {
        $tail = substr($url, $pos + strlen($marker));
        $tail = preg_split('/[\/?#]/', $tail)[0] ?? '';
        if ($tail === '' || !ctype_digit($tail)) {
          throw new TriageError("Could not read an issue number from: $url");
        }
        $iid = (int) $tail;
        break;
      }
    }

    // Project base = everything before "/-/", with any trailing slash removed.
    $base = $url;
    $dashPos = strpos($base, '/-/');
    if ($dashPos !== false) {
      $base = substr($base, 0, $dashPos);
    }
    $base = rtrim($base, '/');

    $parts = parse_url($base);
    if ($parts === false || empty($parts['host']) || empty($parts['path'])) {
      throw new TriageError("Not a full project URL (need scheme://host/group/project): $url");
    }
    $scheme = $parts['scheme'] ?? 'https';
    $origin = $scheme . '://' . $parts['host'];
    $projectPath = trim($parts['path'], '/');
    if ($projectPath === '') {
      throw new TriageError("Missing project path in: $url");
    }

    return [$origin, $projectPath, $iid];
  }

  /**
   * Look the (origin, projectPath) pair up in config and confirm a usable token.
   * Hard-refuses (TriageError) if the project is not configured or has no token.
   */
  private static function resolve(string $origin, string $projectPath, ?int $iid): self {
    $config = self::loadConfig();
    $wantKey = $origin . '/' . $projectPath;

    foreach ($config as $key => $settings) {
      // Normalise each config key the same way as the input.
      [$kOrigin, $kPath] = self::parse($key);
      if (($kOrigin . '/' . $kPath) !== $wantKey) {
        continue;
      }
      $token = is_array($settings) ? (string) ($settings['token'] ?? '') : '';
      if ($token === '') {
        throw new TriageError(
          "REFUSED: project $wantKey is configured but has an empty token. Add a token in config/config.php."
        );
      }
      return new self($kOrigin, $kPath, $token, $iid);
    }

    throw new TriageError(
      "REFUSED: project $wantKey is not in config/config.php. Only configured projects may be triaged."
    );
  }

  /** The configured settings array for this project (token, tag lists, ...). */
  public function settings(): array {
    foreach (self::loadConfig() as $key => $settings) {
      [$kOrigin, $kPath] = self::parse($key);
      if (($kOrigin . '/' . $kPath) === ($this->origin . '/' . $this->projectPath)) {
        return is_array($settings) ? $settings : [];
      }
    }
    return [];
  }

  public function projectPath(): string {
    return $this->projectPath;
  }

  /* ----------------------------------------------------------------------- *
   * HTTP
   * ----------------------------------------------------------------------- */

  /** URL-encoded project id for the API ("project/ai" -> "project%2Fai"). */
  private function projectId(): string {
    return rawurlencode($this->projectPath);
  }

  /**
   * Perform a request against /api/v4. Returns ['status' => int, 'body' => mixed,
   * 'headers' => array]. $path is relative to the project, e.g. "/issues/42".
   */
  private function request(string $method, string $path, array $query = [], ?array $body = null): array {
    $url = $this->origin . '/api/v4/projects/' . $this->projectId() . $path;
    if ($query !== []) {
      $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    // A User-Agent is required: drupalcode.org (and many GitLab instances behind a
    // CDN/WAF) return a 403 to requests that omit it.
    $headers = ['PRIVATE-TOKEN: ' . $this->token, 'Accept: application/json'];
    curl_setopt($ch, CURLOPT_USERAGENT, 'triage-issue/1.0 (+https://github.com/ivanboring/drupal-maintenance-skills)');
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    if ($body !== null) {
      $headers[] = 'Content-Type: application/json';
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $raw = curl_exec($ch);
    if ($raw === false) {
      $err = curl_error($ch);
      curl_close($ch);
      throw new TriageError("FAILED: HTTP request error: $err");
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $rawHeaders = substr($raw, 0, $headerSize);
    $rawBody = substr($raw, $headerSize);

    $decoded = $rawBody === '' ? null : json_decode($rawBody, true);

    if ($status >= 400) {
      $msg = is_array($decoded) ? ($decoded['message'] ?? $decoded['error'] ?? $rawBody) : $rawBody;
      if (is_array($msg)) {
        $msg = json_encode($msg);
      }
      throw new TriageError("FAILED: GitLab API $status for $method $path: $msg");
    }

    return ['status' => $status, 'body' => $decoded, 'headers' => self::parseHeaders($rawHeaders)];
  }

  /** @return array<string,string> lower-cased header name => value (last wins) */
  private static function parseHeaders(string $raw): array {
    $out = [];
    foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
      $c = strpos($line, ':');
      if ($c !== false) {
        $out[strtolower(trim(substr($line, 0, $c)))] = trim(substr($line, $c + 1));
      }
    }
    return $out;
  }

  /** Walk every page of a collection endpoint and return the merged array. */
  private function paginate(string $path, array $query = []): array {
    $perPage = 100;
    $page = 1;
    $all = [];
    while (true) {
      $res = $this->request('GET', $path, $query + ['per_page' => $perPage, 'page' => $page]);
      $batch = is_array($res['body']) ? $res['body'] : [];
      foreach ($batch as $row) {
        $all[] = $row;
      }
      $next = $res['headers']['x-next-page'] ?? '';
      if ($next === '' || count($batch) < $perPage) {
        break;
      }
      $page = (int) $next;
    }
    return $all;
  }

  /**
   * Download the repository archive (zip) at the given ref and write it to
   * $destPath. The response is binary, not JSON, so it is streamed straight to
   * disk rather than going through request(). A null/empty ref uses the
   * project's default branch.
   *
   * Used by the suggest-solution skill to obtain a read-only snapshot of the
   * code at a specific version (branch or tag). It only ever READS the remote
   * repository — there is no checkout, no remote, and nothing is pushed.
   */
  public function downloadArchive(?string $ref, string $destPath): void {
    $query = [];
    if ($ref !== null && $ref !== '') {
      $query['sha'] = $ref;
    }
    $url = $this->origin . '/api/v4/projects/' . $this->projectId() . '/repository/archive.zip';
    if ($query !== []) {
      $url .= '?' . http_build_query($query);
    }

    $fh = fopen($destPath, 'wb');
    if ($fh === false) {
      throw new TriageError("FAILED: cannot open $destPath for writing.");
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_USERAGENT, 'suggest-solution/1.0 (+https://github.com/ivanboring/drupal-maintenance-skills)');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['PRIVATE-TOKEN: ' . $this->token]);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    // Do not follow redirects while carrying the PRIVATE-TOKEN header. A
    // same-host GitLab API response should return the archive directly; any
    // redirect is treated as a failure instead of risking credential leakage to
    // another origin.
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_FILE, $fh);

    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fh);

    if ($ok === false) {
      @unlink($destPath);
      throw new TriageError("FAILED: archive download error: $err");
    }
    if ($status < 200 || $status >= 300) {
      @unlink($destPath);
      $refShown = ($ref !== null && $ref !== '') ? $ref : '(default branch)';
      throw new TriageError("FAILED: GitLab API $status downloading archive at ref '$refShown'.");
    }
  }

  /**
   * Validate a zip archive before extraction. GitLab archives should contain
   * ordinary relative paths under a single top-level directory; reject absolute
   * paths, parent traversal, hidden control characters, and symlink/link entries.
   */
  public static function validateArchiveForExtraction(PharData $archive): void {
    $it = new RecursiveIteratorIterator($archive);
    $prefix = 'phar://' . $archive->getPath() . '/';
    foreach ($it as $file) {
      if (!$file instanceof PharFileInfo) {
        continue;
      }
      $path = $file->getPathName();
      $name = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $file->getFilename();
      $name = str_replace('\\', '/', $name);
      if ($name === '' || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name)) {
        throw new TriageError("REFUSED: archive contains an unsafe path: $name");
      }
      if (preg_match('/[\x00-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u', $name)) {
        throw new TriageError("REFUSED: archive contains a path with hidden/control characters.");
      }
      if ($file->isLink()) {
        throw new TriageError("REFUSED: archive contains a symlink/link entry: $name");
      }
    }
  }

  /* ----------------------------------------------------------------------- *
   * Issue + label operations
   * ----------------------------------------------------------------------- */

  /** GET one issue by its iid. */
  public function getIssue(): array {
    $this->requireIid();
    $res = $this->request('GET', '/issues/' . $this->iid);
    if (!is_array($res['body'])) {
      throw new TriageError("FAILED: unexpected issue response.");
    }
    return $res['body'];
  }

  /** GET an issue's notes (comments), every page, oldest first, system notes dropped. */
  public function getComments(): array {
    $this->requireIid();
    $notes = $this->paginate('/issues/' . $this->iid . '/notes', ['sort' => 'asc', 'order_by' => 'created_at']);
    return array_values(array_filter($notes, static fn($n) => empty($n['system'])));
  }

  /** GET all open issues in the project. */
  public function listOpenIssues(): array {
    return $this->paginate('/issues', ['state' => 'opened']);
  }

  /**
   * GET issues carrying ALL of $labels, filtered by $state
   * ('opened'|'closed'|'all'), newest activity first. When $updatedAfter is
   * given (ISO-8601 or YYYY-MM-DD), only issues updated at/after it are
   * returned. Read-only.
   */
  public function issuesByLabels(array $labels, string $state = 'all', ?string $updatedAfter = null): array {
    $query = ['state' => $state, 'order_by' => 'updated_at', 'sort' => 'desc'];
    if ($labels !== []) {
      $query['labels'] = implode(',', $labels);
    }
    if ($updatedAfter !== null && $updatedAfter !== '') {
      $query['updated_after'] = $updatedAfter;
    }
    return $this->paginate('/issues', $query);
  }

  /**
   * GET the merge requests related to an issue. On drupal.org the issue-fork
   * workflow opens the MR against the main project and references the issue, so
   * a fix already in progress (or merged) shows up here. Uses the given iid, or
   * the client's own iid when null.
   *
   * @return array<int,array> each MR array carries at least 'state' and 'web_url'.
   */
  public function relatedMergeRequests(?int $iid = null): array {
    $iid = $iid ?? $this->iid;
    if ($iid === null) {
      throw new TriageError("No issue iid — build this client from an issue URL or pass an iid.");
    }
    return $this->paginate('/issues/' . $iid . '/related_merge_requests');
  }

  /**
   * Full-text search the issue queue. Returns matching issues (default: open,
   * searching title + description).
   */
  public function searchIssues(string $query, string $in = 'title,description', string $state = 'opened'): array {
    return $this->paginate('/issues', ['search' => $query, 'in' => $in, 'state' => $state]);
  }

  /** Close the issue (GitLab state -> closed). */
  public function closeIssue(): void {
    $this->requireIid();
    $this->request('PUT', '/issues/' . $this->iid, [], ['state_event' => 'close']);
  }

  /** List the project's issue templates as [['key' => ..., 'name' => ...], ...]. */
  public function listIssueTemplates(): array {
    $res = $this->request('GET', '/templates/issues');
    return is_array($res['body']) ? $res['body'] : [];
  }

  /** Get one issue template's content by its key/name. */
  public function getIssueTemplate(string $name): array {
    $res = $this->request('GET', '/templates/issues/' . rawurlencode($name));
    return is_array($res['body']) ? $res['body'] : [];
  }

  /** Add and/or remove labels on the issue in a single PUT. */
  public function changeLabels(array $add, array $remove = []): void {
    $this->requireIid();
    $body = [];
    if ($add !== [])    { $body['add_labels'] = implode(',', $add); }
    if ($remove !== []) { $body['remove_labels'] = implode(',', $remove); }
    if ($body === [])   { return; }
    $this->request('PUT', '/issues/' . $this->iid, [], $body);
  }

  /** Add one or more labels (append-only; existing labels are kept). */
  public function addLabels(array $labels): void {
    $this->changeLabels($labels, []);
  }

  /** Set the issue weight. */
  public function setWeight(int $weight): void {
    $this->requireIid();
    $this->request('PUT', '/issues/' . $this->iid, [], ['weight' => $weight]);
  }

  /**
   * Neutralise GitLab "quick action" slash-commands in an outgoing comment body
   * so they are posted as plain text instead of being executed by GitLab. A
   * quick action fires only when a line BEGINS with "/command" (e.g. /close,
   * /label, /assign, /spend), so we strip the leading slash at the start of any
   * line ("/issue" -> "issue") while leaving inline slashes, URLs (https://...)
   * and file paths (src/Plugin/...) untouched. Applied to EVERY comment this
   * client posts, so all skills get the protection in one place.
   */
  public static function stripSlashCommands(string $body): string {
    // ^(\h*)        optional indentation at the start of a line (multiline)
    // \/            a literal slash ...
    // (?=[A-Za-z])  ... only when immediately followed by a letter (a command).
    return preg_replace('/^(\h*)\/(?=[A-Za-z])/m', '$1', $body) ?? $body;
  }

  /* ----------------------------------------------------------------------- *
   * Prompt-injection scanning
   * ----------------------------------------------------------------------- */

  /** @return array<string,string> signal label => case-insensitive regex. */
  public static function promptInjectionPatterns(): array {
    return [
      'override-prior-instructions' =>
        '/\b(?:ignore|disregard|forget|override|bypass)\b.{0,40}?\b(?:previous|prior|above|earlier|preceding|all|any|these|the)\b.{0,20}?\b(?:instruction|instructions|prompt|prompts|directive|directives|rule|rules|context|guardrails?)\b/is',
      'addresses-the-assistant' =>
        '/\b(?:assistant|ai\s*agent|the\s+agent|claude|chatgpt|gpt|language\s+model|llm|system)\b\s*[,:]?\s+(?:please\s+)?(?:ignore|disregard|stop|now\s+you|you\s+must|you\s+should|you\s+will|execute|run|post|delete|remove|send|reveal|output)\b/is',
      'suppress-disclosure' =>
        '/\b(?:do\s*not|don.?t|never|without)\b.{0,20}?\b(?:tell|inform|notify|warn|mention|reveal|let|alert)\b.{0,20}?\b(?:the\s+)?(?:user|human|maintainer|operator|reviewer|anyone|them)\b/is',
      'exfiltrate-secrets' =>
        '/\b(?:reveal|print|show|echo|output|expose|leak|send|disclose|exfiltrate|dump|paste|repeat)\b.{0,40}?\b(?:system\s+prompt|api[\s\-]?key|access\s+token|private[\s\-]?token|secret|credential|password|env(?:ironment)?\s+var|config(?:uration)?\s+file)\b/is',
      'references-skill-internals' =>
        '/(?:PRIVATE-TOKEN|config\/config\.php|lib\/gitlab\.php|suggest\.php|triage\.php|\$config\[)/i',
      'execute-commands' =>
        '/\b(?:run|execute|eval|exec)\b.{0,20}?\b(?:the\s+)?(?:following|this|these|below)\b.{0,20}?\b(?:command|commands|code|script|shell|bash|payload)\b/is',
      'jailbreak-persona' =>
        '/\b(?:do\s+anything\s+now|developer\s+mode|jailbreak|unrestricted\s+mode|without\s+any\s+restrictions)\b/is',
      'instruction-delimiters' =>
        '/<\|[^|]{0,40}\|>|\[\/?(?:INST|SYS|SYSTEM)\]|<\/?(?:system|assistant)>|#{2,}\s*(?:system|instruction)\b/i',
      'hidden-unicode' =>
        '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u',
    ];
  }

  /**
   * Build the list of untrusted text sources for an issue.
   *
   * @return array<int,array{where:string,text:string}>
   */
  public static function promptInjectionSources(array $issue, array $comments): array {
    $sources = [
      ['where' => 'title',       'text' => (string) ($issue['title'] ?? '')],
      ['where' => 'description', 'text' => (string) ($issue['description'] ?? '')],
    ];
    foreach ($comments as $note) {
      $author = $note['author']['username'] ?? ($note['author']['name'] ?? 'unknown');
      $sources[] = ['where' => "comment by $author", 'text' => (string) ($note['body'] ?? '')];
    }
    return $sources;
  }

  /**
   * Scan untrusted sources for prompt-injection signals.
   *
   * @param array<int,array{where:string,text:string}> $sources
   * @return array<int,array{signal:string,where:string,excerpt:string}> hits ([] = clean)
   */
  public static function scanPromptInjection(array $sources): array {
    $hits = [];
    foreach ($sources as $src) {
      $text = (string) ($src['text'] ?? '');
      if ($text === '') {
        continue;
      }
      foreach (self::promptInjectionPatterns() as $label => $regex) {
        if (preg_match($regex, $text, $m, PREG_OFFSET_CAPTURE)) {
          $at = (int) $m[0][1];
          $start = max(0, $at - 25);
          $excerpt = substr($text, $start, 110);
          $excerpt = trim(preg_replace('/\s+/', ' ', $excerpt) ?? '');
          $hits[] = ['signal' => $label, 'where' => (string) ($src['where'] ?? 'unknown'), 'excerpt' => $excerpt];
        }
      }
    }
    return $hits;
  }

  /** Post a comment on the issue. */
  public function addComment(string $body): void {
    $this->requireIid();
    $body = self::stripSlashCommands($body);
    $this->request('POST', '/issues/' . $this->iid . '/notes', [], ['body' => $body]);
  }

  /** Edit an existing comment (note) on the issue. */
  public function editComment(int $noteId, string $body): void {
    $this->requireIid();
    $body = self::stripSlashCommands($body);
    $this->request('PUT', '/issues/' . $this->iid . '/notes/' . $noteId, [], ['body' => $body]);
  }

  /** GET every label defined in the project. */
  public function listLabels(): array {
    return $this->paginate('/labels');
  }

  /** Create a label with the given name and color (#rrggbb). */
  public function createLabel(string $name, string $color): void {
    $this->request('POST', '/labels', [], ['name' => $name, 'color' => $color]);
  }

  private function requireIid(): void {
    if ($this->iid === null) {
      throw new TriageError("No issue iid — build this client from an issue URL.");
    }
  }

  /* ----------------------------------------------------------------------- *
   * Static helpers for working with a raw issue array
   * ----------------------------------------------------------------------- */

  /** @return string[] label names from an issue array (labels are plain strings in v4). */
  public static function labelNames(array $issue): array {
    $labels = $issue['labels'] ?? [];
    $out = [];
    foreach ($labels as $l) {
      $out[] = is_array($l) ? (string) ($l['name'] ?? '') : (string) $l;
    }
    return array_values(array_filter($out, static fn($s) => $s !== ''));
  }

  /** Value of the first "scope::value" label, or '' if none. */
  public static function scopedValue(array $issue, string $scope): string {
    $prefix = $scope . '::';
    foreach (self::labelNames($issue) as $name) {
      if (strncmp($name, $prefix, strlen($prefix)) === 0) {
        return substr($name, strlen($prefix));
      }
    }
    return '';
  }

  /** True if the issue carries the given exact label. */
  public static function hasLabel(array $issue, string $label): bool {
    return in_array($label, self::labelNames($issue), true);
  }
}
