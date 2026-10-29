<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Intentionally keep business data and country configuration on uninstall.
// A future explicit data-removal tool will handle destructive cleanup.
