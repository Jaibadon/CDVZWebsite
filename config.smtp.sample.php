<?php
/**
 * SMTP credentials — paste these into config.php (gitignored).
 *
 * For cPanel-hosted cadviz.co.nz the typical values are:
 *   SMTP_HOST       = mail.cadviz.co.nz   (or your server's mail hostname)
 *   SMTP_PORT       = 587                 (STARTTLS) — use 465 for implicit SSL
 *   SMTP_ENCRYPTION = 'tls'               ('tls' | 'ssl' | 'none')
 *   SMTP_USER       = accounts@cadviz.co.nz
 *   SMTP_PASS       = the mailbox password (NOT the cPanel login password)
 *
 * Find the exact server hostname in cPanel → Email Accounts → "Connect Devices"
 * → "Mail Client Manual Settings" for the accounts@cadviz.co.nz mailbox.
 */

const SMTP_HOST       = 'mail.cadviz.co.nz';
const SMTP_PORT       = 587;
const SMTP_ENCRYPTION = 'tls';
const SMTP_USER       = 'accounts@cadviz.co.nz';
const SMTP_PASS       = 'PUT-MAILBOX-PASSWORD-HERE';
const SMTP_FROM_EMAIL = 'accounts@cadviz.co.nz';
const SMTP_FROM_NAME  = 'CADViz Accounts';
