<?php
$autoloadFile = dirname(__FILE__) . '/../../../../../autoload.php';
if (!is_file($autoloadFile)) {
    throw new \LogicException('Could not find ' . $autoloadFile . ' in vendor/. Did you run "composer install --dev"?');
}

require $autoloadFile;
