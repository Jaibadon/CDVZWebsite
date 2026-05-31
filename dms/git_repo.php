<?php
/**
 * Thin PHP wrapper around git for per-project IFC version control.
 *
 * Each project gets a bare repo at CADVIZ_GIT_REPOS_PATH/<proj_id>.git. The
 * CADViz "commit revision" handler stages the project's current IFC into
 * the repo's worktree, commits with the staff-authored message, and stores
 * the resulting SHA on the Commits row. Diffs between revisions then become
 * `git diff <oldSha> <newSha> -- '*.ifc'` — and the IFC parser sidecar
 * turns those into structured element-level manifests.
 *
 * Why bare git on the host filesystem (vs gitea/gitlab):
 *   • One directory per project, no service to maintain.
 *   • CADViz already auth-gates everything — no second auth model needed.
 *   • If we want a browsable web UI later, drop gitea on top of these same
 *     repos. Cheap to defer, cheap to add later.
 *
 * Security: every path/ref/message that comes from user input is passed
 * via proc_open's array-arg form so the shell never parses it. Never use
 * shell_exec / exec / passthru in this file.
 *
 * Requirements:
 *   • git installed on the host and on $PATH (>=2.20 recommended for sane
 *     pathspec behaviour). Confirmed available on cPanel-style shared
 *     hosting in essentially every case.
 *   • CADVIZ_GIT_REPOS_PATH defined in config.php (see config.cadviz.sample.php).
 *     This path must be writable by the PHP user AND must NOT be web-
 *     accessible (put it OUTSIDE the web root).
 *   • CADVIZ_GIT_BIN optional override (defaults to 'git' on PATH).
 *
 * Per-repo setup (applied automatically by ensureRepo()):
 *   • `git init --bare`
 *   • A worktree under .work/ for staging operations (one worktree per
 *     commit is overkill; we just `git --work-tree` against a temp dir).
 *
 * Note: there was previously a Python-based IFC normalising diff driver
 * wired into .gitattributes for forensic `git diff` clarity. Dropped
 * because (a) the shared host can't run modern Python and (b) the actual
 * element-level diff for coverage rules comes from comparing
 * Element_Instances rows in MySQL — we use git purely for byte storage of
 * the IFC. `git diff` on an IFC will be noisy; nobody's reading it.
 */

if (!defined('CADVIZ_GIT_REPOS_PATH') && file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
}

class GitRepoException extends \Exception {}

class GitRepo
{
    /** @var string absolute path to the bare repo */
    private $repoDir;

    /** @var string git binary (defaults to 'git' on PATH) */
    private $gitBin;

    /**
     * Open or create the bare repo for a given project. Pass either:
     *   • a Projects.proj_id (int) — repo path = CADVIZ_GIT_REPOS_PATH/<id>.git
     *   • a Projects.git_repo_path (string) — repo path = CADVIZ_GIT_REPOS_PATH/<that>
     *
     * Creates the bare repo with `git init --bare` if it doesn't exist yet
     * (so first-time-commit on a project Just Works).
     */
    public static function forProject($projIdOrPath): self
    {
        if (!defined('CADVIZ_GIT_REPOS_PATH') || CADVIZ_GIT_REPOS_PATH === '') {
            throw new GitRepoException(
                'CADVIZ_GIT_REPOS_PATH is not configured in config.php — ' .
                'see config.cadviz.sample.php.'
            );
        }
        $base = rtrim(CADVIZ_GIT_REPOS_PATH, '/\\');
        if (is_int($projIdOrPath)) {
            $sub = ((int)$projIdOrPath) . '.git';
        } else {
            // Sanity-bound: only [a-zA-Z0-9_.-] in the project's stored path
            // to prevent traversal. Stored values come from the column we
            // wrote ourselves, but defence-in-depth.
            $sub = preg_replace('/[^A-Za-z0-9_.\-]/', '', (string)$projIdOrPath);
            if ($sub === '') throw new GitRepoException('Empty / invalid git_repo_path.');
        }
        $repoDir = $base . DIRECTORY_SEPARATOR . $sub;
        return new self($repoDir);
    }

    private function __construct(string $repoDir)
    {
        $this->repoDir = $repoDir;
        $this->gitBin  = defined('CADVIZ_GIT_BIN') && CADVIZ_GIT_BIN ? CADVIZ_GIT_BIN : 'git';
        $this->ensureRepo();
    }

    /** Create the bare repo if it doesn't exist. Idempotent. */
    private function ensureRepo(): void
    {
        if (!is_dir($this->repoDir)) {
            $parent = dirname($this->repoDir);
            if (!is_dir($parent)) {
                if (!@mkdir($parent, 0750, true) && !is_dir($parent)) {
                    throw new GitRepoException("Cannot create repos parent directory: $parent");
                }
            }
            // We init in a transient working dir then move into place — `git
            // init --bare` is safe on a non-existent path so we just point
            // it at the final target.
            $this->runGit(['init', '--bare', $this->repoDir], /*cwd=*/null);
        }
    }

    /**
     * Stage a single file into the index and commit it. Returns the new SHA.
     *
     * @param string $filePath        Absolute path to the source file on disk.
     * @param string $pathInRepo      Where in the repo the file should live (e.g. "project.ifc").
     * @param string $message         Commit message.
     * @param string $authorName      Author display name.
     * @param string $authorEmail     Author email.
     * @return string                 The 40-char commit SHA.
     */
    public function commitFile(string $filePath, string $pathInRepo, string $message, string $authorName, string $authorEmail): string
    {
        if (!is_file($filePath)) throw new GitRepoException("Source file not found: $filePath");
        if ($message === '')     throw new GitRepoException('Commit message is required.');

        // ── Per-project commit lock ──────────────────────────────────────
        // Transient worktrees stop DIFFERENT projects colliding, but two
        // commits to the SAME project race on the bare repo's branch ref.
        // Serialise them with an exclusive flock on a per-repo lock file
        // (lives inside the git-dir; git ignores unknown top-level files
        // there). Held for the whole commit, released in finally.
        $lockPath = $this->repoDir . DIRECTORY_SEPARATOR . 'cadviz-commit.lock';
        $lockFh = @fopen($lockPath, 'c');
        if ($lockFh === false) {
            throw new GitRepoException("Cannot open commit lock: $lockPath");
        }
        if (!flock($lockFh, LOCK_EX)) {
            fclose($lockFh);
            throw new GitRepoException('Could not acquire commit lock for this project (another publish in progress?).');
        }

        // Use a transient worktree so concurrent commits on different
        // projects don't trip over each other. tempnam + replace-with-dir.
        $workTree = tempnam(sys_get_temp_dir(), 'cadviz_git_wt_');
        @unlink($workTree);
        if (!@mkdir($workTree, 0700)) {
            throw new GitRepoException("Failed to create temp worktree: $workTree");
        }

        try {
            // Copy the file in under its target path
            $dest = $workTree . DIRECTORY_SEPARATOR . $pathInRepo;
            $destDir = dirname($dest);
            if (!is_dir($destDir)) {
                if (!@mkdir($destDir, 0700, true)) {
                    throw new GitRepoException("Failed to create worktree subdir: $destDir");
                }
            }
            if (!@copy($filePath, $dest)) {
                throw new GitRepoException("Failed to copy $filePath → $dest");
            }

            // Common args to point git at this bare repo + transient worktree
            $base = [
                '--git-dir=' . $this->repoDir,
                '--work-tree=' . $workTree,
                '-c', 'user.name='  . $authorName,
                '-c', 'user.email=' . $authorEmail,
            ];

            // If the repo already has commits, check out HEAD into the
            // worktree first so the diff is against parent state, not
            // an empty tree. (Without this every commit would diff against
            // an empty tree.) On the first commit this is a no-op (HEAD
            // doesn't exist yet → swallow the failure).
            try {
                $this->runGit(array_merge($base, ['checkout', '-f', 'HEAD', '--', '.']), $workTree);
            } catch (GitRepoException $e) {
                // Empty repo / first commit — fine.
            }

            // Now overlay the new file (could re-overwrite if checkout pulled an older copy)
            if (!@copy($filePath, $dest)) {
                throw new GitRepoException("Failed to overlay $dest after checkout");
            }

            // Stage + commit
            $this->runGit(array_merge($base, ['add', '--', $pathInRepo]), $workTree);

            // --allow-empty so a re-commit of identical content still produces
            // a SHA — useful when staff "publish revision" but didn't actually
            // change anything yet (probably an error on their part, but the
            // commit log preserves the intent).
            $this->runGit(array_merge($base, ['commit', '--allow-empty', '-m', $message]), $workTree);

            // Read the new HEAD SHA
            [$out, , ] = $this->runGit(['--git-dir=' . $this->repoDir, 'rev-parse', 'HEAD'], null);
            $sha = trim($out);
            if (!preg_match('/^[0-9a-f]{40}$/', $sha)) {
                throw new GitRepoException("git rev-parse returned unexpected: $sha");
            }
            return $sha;
        } finally {
            $this->rrmdir($workTree);
            flock($lockFh, LOCK_UN);
            fclose($lockFh);
        }
    }

    /** Get the raw content of a file at a specific commit. */
    public function showFile(string $sha, string $pathInRepo): string
    {
        $this->validateSha($sha);
        [$out, , ] = $this->runGit(
            ['--git-dir=' . $this->repoDir, 'show', $sha . ':' . $pathInRepo],
            null,
            /*binary=*/true
        );
        return $out;
    }

    /**
     * Diff two commits, restricted to the given pathspec (e.g. '*.ifc').
     * Returns the unified diff as a string. Empty string = no changes.
     */
    public function diff(string $shaA, string $shaB, string $pathSpec = ''): string
    {
        $this->validateSha($shaA);
        $this->validateSha($shaB);
        $args = ['--git-dir=' . $this->repoDir, 'diff', $shaA, $shaB];
        if ($pathSpec !== '') {
            $args[] = '--';
            $args[] = $pathSpec;
        }
        [$out, , ] = $this->runGit($args, null);
        return $out;
    }

    /** Walk the commit history. Returns array of [sha, message, author, date]. */
    public function log(int $limit = 50): array
    {
        $args = [
            '--git-dir=' . $this->repoDir,
            'log',
            '--format=%H%x09%an%x09%ae%x09%aI%x09%s',
            '-n', (string)$limit,
        ];
        try {
            [$out, , ] = $this->runGit($args, null);
        } catch (GitRepoException $e) {
            // Empty repo
            return [];
        }
        $rows = [];
        foreach (explode("\n", trim($out)) as $line) {
            if ($line === '') continue;
            $parts = explode("\t", $line, 5);
            if (count($parts) !== 5) continue;
            $rows[] = [
                'sha'          => $parts[0],
                'author_name'  => $parts[1],
                'author_email' => $parts[2],
                'date'         => $parts[3],
                'message'      => $parts[4],
            ];
        }
        return $rows;
    }

    /** Returns true if the repo has at least one commit. */
    public function hasCommits(): bool
    {
        try {
            $this->runGit(['--git-dir=' . $this->repoDir, 'rev-parse', '--verify', 'HEAD'], null);
            return true;
        } catch (GitRepoException $e) {
            return false;
        }
    }

    /** Returns the current HEAD SHA or empty string if no commits. */
    public function headSha(): string
    {
        if (!$this->hasCommits()) return '';
        [$out, , ] = $this->runGit(['--git-dir=' . $this->repoDir, 'rev-parse', 'HEAD'], null);
        return trim($out);
    }

    public function repoDir(): string
    {
        return $this->repoDir;
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function validateSha(string $sha): void
    {
        if (!preg_match('/^[0-9a-f]{4,40}$/', $sha)) {
            throw new GitRepoException("Refusing dangerous-looking SHA: $sha");
        }
    }

    /**
     * Run git with the given argv. Returns [stdout, stderr, exit_code].
     * Throws GitRepoException if exit code is non-zero.
     *
     * NEVER use shell_exec/exec/passthru here — they invoke /bin/sh which
     * parses metacharacters. proc_open with an array of args bypasses the
     * shell entirely.
     */
    private function runGit(array $argv, ?string $cwd, bool $binary = false): array
    {
        $cmd = array_merge([$this->gitBin], $argv);
        $descSpec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];
        // proc_open with an array argv was added in PHP 7.4 — checked in
        // overview.md ("This is a PHP 7.4+ app").
        $proc = @proc_open($cmd, $descSpec, $pipes, $cwd);
        if (!is_resource($proc)) {
            throw new GitRepoException('proc_open failed for: ' . implode(' ', $cmd));
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            throw new GitRepoException(
                'git ' . implode(' ', array_slice($argv, 0, 3)) . " ... exit $code: " . trim($err)
            );
        }
        return [$out, $err, $code];
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = @scandir($dir);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $p = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($p) && !is_link($p)) $this->rrmdir($p);
            else                            @unlink($p);
        }
        @rmdir($dir);
    }

}
