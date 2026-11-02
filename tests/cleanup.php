<?php
require '/var/www/html/wp-load.php';
if (wp_get_environment_type() !== 'local') { fwrite(STDERR, "Refusing cleanup outside local QA.\n"); exit(1); }
$records = CB2B\Plugin::snapshot()['records'] ?? [];
if ($records && count(array_filter($records, static fn($r) => !str_starts_with($r['name'], 'QA '))) !== 0) {
    fwrite(STDERR, "Refusing to remove a non-QA snapshot.\n"); exit(1);
}
delete_option(CB2B\Plugin::key());
delete_option(CB2B\Plugin::key() . '_status');
echo "Synthetic local snapshot removed. No source request made.\n";
