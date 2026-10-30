<?php
// Run on the IdP server: php tools/check-simplesaml.php /absolute/path/to/simplesamlphp
// Loads classes only; does not instantiate an auth source or bootstrap Moodle.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
if ($argc !== 2) {
    fwrite(STDERR, "Usage: php tools/check-simplesaml.php /absolute/path/to/simplesamlphp\n");
    exit(2);
}
$root = rtrim($argv[1], '/\\');
$autoload = $root . '/src/_autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Missing $autoload; supply the SimpleSAMLphp 2.x installation root, not its public directory.\n");
    exit(1);
}
try {
    require_once($autoload);
    $module = \SimpleSAML\Module::getModuleDir('moodle');
    echo "Module directory: $module\n";
    echo 'Resolved directory: ' . (realpath($module) ?: '(missing or broken symlink)') . "\n";
    if (!\SimpleSAML\Module::isModuleEnabled('moodle')) {
        throw new \RuntimeException("Enable 'moodle' => true in SimpleSAMLphp's module.enable configuration.");
    }
    $file = $module . '/src/Auth/Source/External.php';
    if (!is_readable($file)) {
        throw new \RuntimeException("Missing/unreadable $file. Copy or symlink this plugin's moodle directory as modules/moodle.");
    }
    $class = 'SimpleSAML\\Module\\moodle\\Auth\\Source\\External';
    if (!class_exists($class)) {
        throw new \RuntimeException("Autoload failed for $class. Check the file's namespace/class and filename case.");
    }
    $reflection = new \ReflectionClass($class);
    echo 'Loaded class file: ' . $reflection->getFileName() . "\n";
    echo 'Parent: ' . ($reflection->getParentClass() ? $reflection->getParentClass()->getName() : '(none)') . "\n";
    $resolved = \SimpleSAML\Module::resolveClass('moodle:External', 'Auth\\Source', '\\SimpleSAML\\Auth\\Source');
    echo "PASS: SimpleSAMLphp resolved $resolved as an authentication source.\n";
} catch (\Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
}
