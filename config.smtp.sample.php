<?php
/**
 * SMTP / Google OAuth 2.0 (XOAUTH2) credentials — paste these into config.php.
 *
 * Google deprecated SMTP password auth in 2024. We use OAuth 2.0 / XOAUTH2
 * instead. Setup is a one-time thing.
 *
 * SETUP (once):
 *
 *  1. Confirm accounts@cadviz.co.nz is a "Send mail as" alias on
 *     erik@cadviz.co.nz's Gmail account:
 *       erik@'s Gmail → Settings (cog) → See all settings → Accounts and Import
 *       → "Send mail as" → "Add another email address"
 *       → Name: "CADViz Accounts"  Email: accounts@cadviz.co.nz
 *       → "Treat as an alias" — checked
 *       → Verify via the verification email (which goes to the Google Group
 *         and forwards to Erik). Click the verification link.
 *     Once verified, Erik can send email with From: accounts@cadviz.co.nz
 *     from his Gmail (and so can our app, while authenticated as Erik).
 *
 *  2. Create a Google Cloud OAuth 2.0 client:
 *       https://console.cloud.google.com/  →  pick (or create) a project
 *       → APIs & Services → Library → enable "Gmail API"
 *       → APIs & Services → OAuth consent screen
 *           User type: Internal (Workspace) — keeps it locked to cadviz.co.nz
 *           App name:  CADViz Timesheet
 *           Add the scope:  https://mail.google.com/
 *       → APIs & Services → Credentials → Create Credentials → OAuth client ID
 *           Application type: Web application
 *           Authorised redirect URI:
 *             https://remote.cadviz.co.nz/smtp_oauth_callback.php
 *       → Copy the Client ID and Client Secret into the constants below.
 *
 *  3. Deploy this file's constants into config.php, then visit
 *     menu.php → "Connect Google SMTP" — that runs the OAuth dance and
 *     stores the refresh_token in Smtp_Tokens.
 *
 *  4. (Optional) Also set up DKIM signing for cadviz.co.nz in Workspace
 *     Admin → Apps → Gmail → Authenticate email — improves deliverability.
 *
 * From that point on, the access_token refreshes automatically and outbound
 * email goes through smtp.gmail.com:587 STARTTLS with AUTH XOAUTH2.
 */

const SMTP_AUTH_MODE       = 'oauth2';            // 'oauth2' (recommended) or 'login'
const SMTP_HOST            = 'smtp.gmail.com';
const SMTP_PORT            = 587;
const SMTP_ENCRYPTION      = 'tls';
const SMTP_OAUTH_USER      = 'erik@cadviz.co.nz'; // the Google account that authenticates
const SMTP_FROM_EMAIL      = 'accounts@cadviz.co.nz';  // the verified alias to send AS
const SMTP_FROM_NAME       = 'CADViz Accounts';
const GOOGLE_OAUTH_CLIENT_ID     = 'PUT-CLIENT-ID-HERE.apps.googleusercontent.com';
const GOOGLE_OAUTH_CLIENT_SECRET = 'PUT-SECRET-HERE';
const GOOGLE_OAUTH_REDIRECT_URI  = 'https://remote.cadviz.co.nz/smtp_oauth_callback.php';

// ── Legacy AUTH LOGIN fallback (only used when SMTP_AUTH_MODE = 'login') ──
// Some hosts still let you do this. Most consumer mail providers no longer.
// const SMTP_USER = 'accounts@cadviz.co.nz';
// const SMTP_PASS = '';
