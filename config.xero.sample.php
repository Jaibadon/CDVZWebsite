<?php
/**
 * Xero credentials — copy these constants into config.php (gitignored).
 *
 * SETUP STEPS (Erik does this once):
 *   1. Sign in at https://developer.xero.com/app/manage and create a new
 *      "Web App" connection. Suggested name: "CADViz Timesheet".
 *   2. Set the Redirect URI EXACTLY to:
 *        https://remote.cadviz.co.nz/xero_callback.php
 *      (use http://localhost/xero_callback.php for local dev — list both)
 *   3. After creation, copy the Client ID and generate a Client Secret.
 *      Paste them below into config.php.
 *   4. After deploying, log in to remote.cadviz.co.nz and click
 *      Menu → "Connect to Xero". You'll be redirected to Xero to grant
 *      access to the CADViz organisation. Tokens are stored in
 *      Xero_Tokens and refresh automatically.
 *
 * Scopes requested:
 *   - offline_access      — required to receive refresh_token
 *   - accounting.contacts — needed to attach invoices to contacts
 *   - accounting.transactions — read/write invoices
 *   - accounting.transactions.read — for status sync GETs
 */

const XERO_CLIENT_ID     = 'PUT-YOUR-CLIENT-ID-HERE';
const XERO_CLIENT_SECRET = 'PUT-YOUR-CLIENT-SECRET-HERE';
const XERO_REDIRECT_URI  = 'https://remote.cadviz.co.nz/xero_callback.php';
const XERO_SCOPES        = 'offline_access accounting.contacts accounting.transactions accounting.transactions.read';
