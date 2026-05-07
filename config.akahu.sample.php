<?php
/**
 * Akahu config — there isn't any.
 *
 * Akahu's auth model is two static tokens (App Token + User Token) that
 * never expire and don't refresh. Storing them in `config.php` would
 * make rotating them require a code deploy, which is silly. Instead
 * they live in the database (Akahu_Tokens table) and are entered via
 * /akahu_connect.php as a one-time admin task.
 *
 * Setup:
 *   1. Apply the migration:
 *        migrations/add_akahu_bankfeed.sql
 *   2. Sign up at https://genie.akahu.io
 *   3. Connect your Westpac (or other NZ bank) account through Akahu's
 *      flow — you log into your bank inside Akahu's iframe; CADViz
 *      never sees your bank credentials.
 *   4. In Genie → Settings → Developers, copy the App Token AND the
 *      User Token.
 *   5. As erik or jen, visit /akahu_connect.php, paste both, click Save.
 *      The page smoke-tests the connection on save (calls /me and
 *      /accounts) so you know immediately if the tokens are wrong.
 *   6. Visit /akahu_sync.php — first sync pulls 90 days of history.
 *   7. Add the cron line:
 *        30 * * * * cd /home/<user>/public_html && php akahu_sync.php cron \
 *                       >> /home/<user>/public_html/logs/akahu_sync.log 2>&1
 *
 * That's it. Nothing in config.php is required.
 *
 * If you later want to rotate the tokens (e.g. you re-generated them in
 * Genie because of a security incident), repeat step 5 — the new tokens
 * overwrite the old ones in Akahu_Tokens.
 *
 * If you want to disconnect Akahu entirely, click Disconnect on
 * /akahu_connect.php (deletes the Akahu_Tokens row). The cron will then
 * skip with "Akahu not connected — sync skipped" until reconnected.
 *
 * For comparison:
 *   - config.xero.sample.php   — Xero needs CLIENT_ID + SECRET in
 *                                config.php because OAuth refresh
 *                                requires them.
 *   - config.smtp.sample.php   — Gmail SMTP needs OAuth client info in
 *                                config.php for the same reason.
 *   - this file                — Akahu needs nothing in config.php.
 */
