<?php

$required = [
    'bcmath', 'ctype', 'curl', 'dom', 'fileinfo', 'filter', 'hash', 'intl',
    'json', 'libxml', 'mbstring', 'openssl', 'pcntl', 'pcre', 'PDO', 'pdo_pgsql',
    'posix', 'session', 'tokenizer', 'xml', 'xmlwriter', 'zip',
];
$missing = array_filter($required, fn (string $extension): bool => ! extension_loaded($extension));
foreach ($missing as $extension) {
    fwrite(STDERR, "Missing PHP extension: {$extension}".PHP_EOL);
}
$coverage = (extension_loaded('pcov') && filter_var(ini_get('pcov.enabled'), FILTER_VALIDATE_BOOLEAN))
    || (extension_loaded('xdebug') && in_array('coverage', xdebug_info('mode'), true));
if (! $coverage) {
    fwrite(STDERR, 'Missing enabled PCOV/Xdebug coverage driver'.PHP_EOL);
}
if (PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600) {
    fwrite(STDERR, 'PHP 8.5.x is required by this application'.PHP_EOL);
}
exit(count($missing) || ! $coverage || (PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600) ? 2 : 0);
