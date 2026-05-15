<?php
/**
 * CADViz document-management config — paste these into config.php.
 *
 * Required by:
 *   • git_repo.php  (CADVIZ_GIT_REPOS_PATH for the bare per-project IFC repos)
 *   • drive_client.php (Drive OAuth — for keynotes.txt edit-from-web and
 *     similar server-side Drive file ops)
 *
 * SETUP (once per deployment):
 *
 *  1. Pick a path on the host for the bare git repos that's:
 *       • writable by the PHP user
 *       • NOT under the web root (we never want bare repos to be HTTP-fetchable)
 *     On cPanel-style hosting your home directory works:
 *       mkdir -p /home/<user>/cadviz_repos && chmod 750 /home/<user>/cadviz_repos
 *
 *  2. (Optional) pick a path for the long-term blob shadow archive — only
 *     used later if/when we shadow-copy Revit backups that are about to age
 *     out of the per-file cap.
 *
 *  3. Drive OAuth — re-uses the existing Google Cloud OAuth client you set
 *     up for SMTP (see config.smtp.sample.php). From the same Cloud Console
 *     project:
 *       → APIs & Services → Library → enable "Google Drive API"
 *       → APIs & Services → OAuth consent screen
 *           Add the scope:  https://www.googleapis.com/auth/drive
 *           (Read+write; needed so the keynotes editor can save back, and
 *           so future activate-DMS / PDF-proxy flows can enumerate files.)
 *       → APIs & Services → Credentials → existing OAuth 2.0 client
 *           Authorised redirect URIs: add
 *             https://remote.cadviz.co.nz/drive_oauth_callback.php
 *     Then set the constant below.
 *
 *  4. Run migrations/add_drive_oauth.sql to create the Drive_Tokens table.
 *
 *  5. From the CADViz menu, click "Connect Google Drive" — completes the OAuth
 *     flow once. Refresh token is auto-persisted, access token auto-refreshes.
 *
 *  6. Raise Revit's max-backups setting on every staff machine (only
 *     relevant to the commit pipeline, not to keynotes editing):
 *       Revit → File → Options → File Locations → Maximum Backups: 100
 */

// ── Bare git repos: one directory per project, never web-accessible ──────
const CADVIZ_GIT_REPOS_PATH = '/home/PUT-USER-HERE/cadviz_repos';

// ── Long-term blob shadow archive for .rvt backups (reserved) ────────────
const CADVIZ_BLOB_ARCHIVE_PATH = '/home/PUT-USER-HERE/cadviz_blob_archive';

// ── Google Drive OAuth (reuses GOOGLE_OAUTH_CLIENT_ID/SECRET from SMTP) ──
const GOOGLE_OAUTH_DRIVE_REDIRECT_URI = 'https://remote.cadviz.co.nz/drive_oauth_callback.php';

// ── Optional: override the git binary (defaults to 'git' on $PATH) ───────
// const CADVIZ_GIT_BIN = '/usr/local/bin/git';
