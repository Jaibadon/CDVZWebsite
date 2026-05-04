# Diagnosing `ERR_HTTP2_PROTOCOL_ERROR`

If a page shows **"This site can't be reached… ERR_HTTP2_PROTOCOL_ERROR"**
in Chrome / Edge (Firefox usually says
"PR_END_OF_FILE_ERROR"), the browser is telling you the server
sent a malformed HTTP/2 response — the connection was cut mid-stream
or the response body didn't match what the headers promised.

## The 80% case — already fixed by db_connect.php

This codebase had `display_errors=1` on, which leaks PHP warning text
straight into the response body. As soon as PHP emits a notice or
deprecation mid-page, HTTP/2 marks the response malformed and Chrome
aborts.

The fix is now in [db_connect.php](db_connect.php):

1. **`ob_start(null, 0)`** at the very top — buffer the entire
   response, never auto-flush mid-page.
2. **`display_errors = 0`** in production — error text never reaches
   the response body.
3. **`log_errors = 1`** — errors go to the cPanel error log instead.
4. **`register_shutdown_function`** — on a fatal error, discard the
   partial buffer and emit a clean 500 page. The browser gets a
   well-formed response either way.

If you still see the error after deploying these changes, work through
the rest of this checklist.

---

## How to investigate when it happens

### 1. Look at the cPanel error log first
Errors are logged regardless of `display_errors`. cPanel → **Errors**.
Look for the timestamp matching when you saw the failure. The top hit
is almost always the cause.

Common entries:
- `PHP Fatal error: Allowed memory size of … exhausted` → script needs
  more memory or has a loop bug. Bump `memory_limit` in php.ini, or
  fix the bug.
- `PHP Fatal error: Uncaught PDOException` with the SQL → fix the
  query.
- `PHP Fatal error: Maximum execution time of 120 seconds exceeded` →
  query is too slow; index it, or rewrite. We already set 120s.
- `mod_fcgid: read data timeout in 45 seconds` → Apache killed PHP
  before it finished. Same root cause as above.

### 2. Reproduce in development with errors visible
Set `CADVIZ_DEBUG=1` in your shell environment (or add
`SetEnv CADVIZ_DEBUG 1` to a development `.htaccess`). Reload the
failing page — you'll see the actual PHP error rendered into the
fatal-catcher panel.

### 3. Test on HTTP/1.1
Some hosts let you bypass HTTP/2 to confirm the failure is HTTP/2
specific. Add to `.htaccess`:

    Protocols http/1.1

Reload — if the page loads with H2 disabled, the issue is "PHP is
emitting bad output" rather than "the page itself is broken".

### 4. Check for BOM / stray whitespace
A UTF-8 BOM (3 bytes `EF BB BF`) before `<?php` or whitespace after
`?>` becomes part of the response body. With output buffering this
shouldn't break HTTP/2 directly, but combined with `header()` calls
it triggers "headers already sent" warnings.

To find offending files (Linux/Mac/Git Bash):

    cd /home/<user>/public_html
    grep -rln $'^\xEF\xBB\xBF' --include='*.php' .

To strip a BOM from a single file:

    php -r 'file_put_contents($f, ltrim(file_get_contents($f="filename.php"), "\xEF\xBB\xBF"));'

### 5. Slow queries
Pages that join Timesheets × Projects × Clients can run >30s when
those tables grow. The `EXPLAIN` of the query shows which indexes are
missing. Common cure: run [migrations/schema_upgrades.sql](migrations/schema_upgrades.sql)
to add the indexes that were missing in the legacy MSSQL port.

### 6. Apache mod_security
If your host has mod_security running, it will sometimes cancel a
response mid-stream when the body matches a "rule" (SQL keywords,
`<script>`, etc.). Test by adding to `.htaccess`:

    <IfModule mod_security.c>
      SecRuleEngine Off
    </IfModule>

If that fixes it, work with the host to whitelist the specific rule,
not blanket-disable.

### 7. PHP-FPM / mod_php worker crash
A segfault in mod_php (rare, but seen on shared cPanel hosts with
old extensions) leaves no PHP error in the log. The Apache error log
will have a SIGSEGV or SIGBUS line. Fix is typically to switch the
PHP version (cPanel → Select PHP Version) and try again.

### 8. Network-side noise
Cloudflare, transparent proxies, antivirus interception (ESET, Bitdefender)
can also corrupt HTTP/2 frames. Confirm by:

- Loading the same URL from a different network (4G hotspot)
- Or directly via `curl --http2 https://remote.cadviz.co.nz/page.php`

If `curl` works but Chrome fails, the issue is on the user side.

---

## Specific page that's been flaky: `invoice_gen.php`

Before today's fix, `invoice_gen.php`'s flow was:

1. SELECT timesheets
2. INSERT into Invoices (with retry-on-duplicate)
3. Loop UPDATE every timesheet
4. `header('Location: invoice_edit.php?Invoice_No=N')` redirect

If step 3 timed out or threw, the redirect never fired and the page
would emit the HTML below the redirect (which is meant to be
unreachable). The shutdown handler now catches that case and emits a
clean 500 error page instead of leaking partial content.

---

## Permanent vs. temporary

The user reports "sometimes temporary, sometimes permanent" failures.
That pattern matches:

- **Slow query** → temporary (load-dependent — the same query runs in
  500ms when DB is idle, 60s when it's busy).
- **Fatal error / undefined variable** → permanent (always fires in
  the same code path).
- **Bad data → exception → no try/catch** → permanent for that
  specific record.

The shutdown handler now ensures permanent failures show a
**clean 500 page** rather than a network-level
`ERR_HTTP2_PROTOCOL_ERROR`, so it'll be obvious which class you're
hitting next time.
