<?php
// The checklist is just the quote with rates/subtotals hidden — quote.php
// handles both via the ?nomoney flag.
$_GET['nomoney'] = '1';
require __DIR__ . '/quote.php';
