<?php

namespace Jfortunato\ImageOptimizer;

use Assert\Assertion;
use Assert\AssertionFailedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class OptimizeImages extends Command
{
    const NODE_MODULES_BIN = __DIR__ . '/../node_modules/.bin';

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     * @throws AssertionFailedException
     */
    public function __invoke(InputInterface $input, OutputInterface $output)
    {
        // create some whitespace
        $output->writeln("");
        $output->writeln("");
        $output->writeln("");

        try {
            // assert that the system has all required shell commands
            $nodeModulesBin = self::NODE_MODULES_BIN;
            Assertion::notEmpty(trim(shell_exec('which npm')), "Please install npm");
            Assertion::notEmpty(trim(shell_exec("which $nodeModulesBin/imagemin")), "Please run npm install.");
            Assertion::notEmpty(trim(shell_exec("which $nodeModulesBin/mozjpeg")), "Please run npm install.");
            Assertion::notEmpty(trim(shell_exec("which $nodeModulesBin/pngquant")), "Please run npm install.");

            $inputDirectory = realpath(rtrim($input->getArgument('input-directory'), '/'));
            $outputDirectory = realpath(rtrim($input->getArgument('output-directory'), '/'));

            Assertion::directory($inputDirectory, 'Please make sure the input directory exists before running this script.');
            Assertion::directory($outputDirectory, 'Please make sure the output directory exists before running this script.');
        } catch (\Exception $e) {
            return $output->writeln($e->getMessage());
        }

        // We want a mapping of every image to its last modified time. We will then do the same with the
        // already optimized directory (if it exists) and compare them so that we can keep the optimized
        // directory in sync with the raw images
        $onlyInclude = $input->getOption('only-include');
        $rawImages = $this->mapImagesInDirectoryWithLastModifiedTime($inputDirectory, $onlyInclude);
        $optimizedImages = $this->mapImagesInDirectoryWithLastModifiedTime($outputDirectory, $onlyInclude, $outputDirectory);

        // TODO get total file size of all raw images, and ensure the system has at least enough disk space for that amount plus some padding

        // 1) optimize new raw images
        // 2) optimize already optimized images whose raw counterpart has been modified
        foreach ($rawImages as $rawImage => $mtime) {
            // if the raw image does not exist as an optimized image, then optimize it
            if (!array_key_exists($rawImage, $optimizedImages)) {
                $output->writeln("Optimizing New Image: $rawImage");
                $this->optimizeImage($rawImage, $outputDirectory);
                continue;
            }

            // if the optimized image is older than the raw image, then re-optimize it
            $optimizedImageMTime = $optimizedImages[$rawImage];

            if ((int) $optimizedImageMTime < (int) $mtime) {
                $output->writeln("Re-optimizing Modified Image: $rawImage");
                $this->optimizeImage($rawImage, $outputDirectory);
            }
        }

        // 3) delete any optimized images that no longer exist as raw images
        if ($input->getOption('no-delete') === false) {
            foreach ($optimizedImages as $optimizedImage => $mtime) {
                if (!array_key_exists($optimizedImage, $rawImages)) {
                    // don't forget to prefix the output directory
                    $optimizedImage = $outputDirectory . $optimizedImage;

                    $output->writeln("Removing optimized image that no longer exists: $optimizedImage");

                    unlink($optimizedImage);
                }
            }
        }

        $output->writeln("");
        $output->writeln("");
        $output->writeln("");
    }

    /**
     * @param string $directory
     * @param array $onlyInclude
     * @param string|null $excludePrefix
     * @return array
     */
    private function mapImagesInDirectoryWithLastModifiedTime(string $directory, array $onlyInclude = [], string $excludePrefix = null): array
    {
        $images = explode(PHP_EOL, trim(shell_exec("find $directory -iregex '.*\.\(jpg\|gif\|png\|svg\|jpeg\)$'")));

        // remove empty string on initial run
        $images = array_filter($images);

        $map = [];

        foreach ($images as $image) {
            // if the user specified to only include certain files, don't include any filepath
            // that does not contain the search string
            if (!empty($onlyInclude)) {
                $found = false;

                foreach ($onlyInclude as $item) {
                    if (strpos($image, $item) !== false) {
                        $found = true;
                    }
                }

                if (!$found) {
                    continue;
                }
            }

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

    /**
     * @param $rawImage
     * @param $outputDirectory
     */
    private function optimizeImage($rawImage, $outputDirectory) {
        $pathinfo = pathinfo($rawImage);

        $directory = $outputDirectory . $pathinfo['dirname'];

        if (!file_exists($directory)) {
            mkdir($directory, 0777, true);
        }

        $filename = $directory . '/' . $pathinfo['basename'];

        shell_exec("./node_modules/.bin/imagemin --plugin=mozjpeg --plugin=pngquant --plugin=gifsicle --plugin=svgo '$rawImage' > '$filename'");
    }
}
