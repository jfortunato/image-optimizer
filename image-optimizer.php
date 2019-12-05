#!/usr/bin/env php
<?php

// set to run indefinitely if needed
set_time_limit(0);

require_once __DIR__ . '/vendor/autoload.php';


// create some whitespace
echo "" . PHP_EOL;
echo "" . PHP_EOL;
echo "" . PHP_EOL;

try {
    \Assert\Assertion::count($argv, 3);

    // assert that the system has all required shell commands
    \Assert\Assertion::notEmpty(trim(shell_exec('which npm')));
    \Assert\Assertion::notEmpty(trim(shell_exec('which imagemin')));
    \Assert\Assertion::notEmpty(trim(shell_exec('npm list -g | grep imagemin-mozjpeg')));

    $inputDirectory = rtrim($argv[1], '/');
    $outputDirectory = rtrim($argv[2], '/');

    \Assert\Assertion::directory($inputDirectory, 'Please make sure the input directory exists before running this script.');
    \Assert\Assertion::directory($outputDirectory, 'Please make sure the output directory exists before running this script.');
} catch (\Exception $e) {
    exit($e->getMessage() . PHP_EOL);
}

function mapImagesInDirectoryWithLastModifiedTime(string $directory, string $excludePrefix = null): array {
    $images = explode(PHP_EOL, trim(shell_exec("find $directory -iregex '.*\.\(jpg\|gif\|png\|svg\|jpeg\)$'")));

    // remove empty string on initial run
    $images = array_filter($images);

    $map = [];

    foreach ($images as $image) {
        // make sure we get the mtime before stripping off a prefix
        $mtime = trim(shell_exec("stat -c %Y '$image'"));

        if ($excludePrefix !== null) {
            $regex = preg_quote($excludePrefix, '/');

            $image = preg_replace("/^$regex/", '', $image);
        }

        $map[$image] = $mtime;
    }

    return $map;
}


function optimizeImage($rawImage, $outputDirectory) {
    $pathinfo = pathinfo($rawImage);

    $directory = $outputDirectory . $pathinfo['dirname'];

    if (!file_exists($directory)) {
        mkdir($directory, 0777, true);
    }

    $filename = $directory . '/' . $pathinfo['basename'];

//    shell_exec("imagemin --plugin=mozjpeg --plugin=optipng --plugin=gifsicle --plugin=svgo '$rawImage' > '$filename'");
    shell_exec("imagemin '$rawImage' > '$filename'");
}

// We want a mapping of every image to its last modified time. We will then do the same with the
// already optimized directory (if it exists) and compare them so that we can keep the optimized
// directory in sync with the raw images
$rawImages = mapImagesInDirectoryWithLastModifiedTime($inputDirectory);
$optimizedImages = mapImagesInDirectoryWithLastModifiedTime($outputDirectory, $outputDirectory);

// 1) optimize new raw images
// 2) optimize already optimized images whose raw counterpart has been modified
foreach ($rawImages as $rawImage => $mtime) {
    // if the raw image does not exist as an optimized image, then optimize it
    if (!array_key_exists($rawImage, $optimizedImages)) {
        echo "Optimizing New Image: $rawImage" . PHP_EOL;
        optimizeImage($rawImage, $outputDirectory);
        continue;
    }

    // if the optimized image is older than the raw image, then re-optimize it
    $optimizedImageMTime = $optimizedImages[$rawImage];

    if ((int) $optimizedImageMTime < (int) $mtime) {
        echo "Re-optimizing Modified Image: $rawImage" . PHP_EOL;
        optimizeImage($rawImage, $outputDirectory);
    }
}

// 3) delete any optimized images that no longer exist as raw images
foreach ($optimizedImages as $optimizedImage => $mtime) {
    if (!array_key_exists($optimizedImage, $rawImages)) {
        echo "Removing optimized image that no longer exists: $optimizedImage" . PHP_EOL;

        // don't forget to prefix the output directory
        $optimizedImage = $outputDirectory . $optimizedImage;

        unlink($optimizedImage);
    }
}

echo "" . PHP_EOL;
echo "" . PHP_EOL;
echo "" . PHP_EOL;