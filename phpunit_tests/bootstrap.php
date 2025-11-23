<?php

// Locate autoloader by walking up the directory tree
// We start from the folder the current script is running in
$projectFolder  = __DIR__;
$autoloaderPath = null;

// Walk up directories looking for vendor/autoload.php
$level = 0;
while (true) {
    $candidatePath = $projectFolder . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

    if (file_exists($candidatePath)) {
        $autoloaderPath = $candidatePath;
        break;
    }

    // Don't look more than 4 levels up
    if ($level > 4) {
        break;
    }

    $parentDir = dirname($projectFolder);
    if ($parentDir === $projectFolder) { // Reached the filesystem root
        break;
    }

    ++$level;
    $projectFolder = $parentDir;
}

if (null === $autoloaderPath) {
    die('Error: Unable to locate vendor/autoload.php. Please run `composer install` in the project root.');
}

require_once $autoloaderPath;

use Dotenv\Dotenv;

$dotenv = Dotenv::createMutable($projectFolder, ['.env', '.env.local', '.env.development', '.env.production'], false);
$dotenv->safeLoad();
