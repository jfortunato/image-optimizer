<?php

namespace Jfortunato\ImageOptimizer;

use Assert\Assertion;
use Assert\AssertionFailedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

final class OptimizeImages extends Command
{
    const NODE_MODULES_BIN = __DIR__ . '/../node_modules/.bin';

    const CHUNK_SIZE = 5000;

    /**
     * @var string
     */
    private $inputDirectory;
    /**
     * @var string
     */
    private $outputDirectory;

    /**
     * @param string $inputDirectory
     * @param string $outputDirectory
     * @throws AssertionFailedException
     */
    public function __construct(string $inputDirectory, string $outputDirectory)
    {
        $this->inputDirectory = realpath(rtrim($inputDirectory, '/'));
        $this->outputDirectory = realpath(rtrim($outputDirectory, '/'));

        Assertion::directory($this->inputDirectory, 'Please make sure the input directory exists before running this script.');
        Assertion::directory($this->outputDirectory, 'Please make sure the output directory exists before running this script.');
        // assert that the system has all required shell commands
        $nodeModulesBin = self::NODE_MODULES_BIN;
        Assertion::notEmpty(trim(shell_exec('which npm')), "Please install npm");
        Assertion::notEmpty(trim(shell_exec("which $nodeModulesBin/imagemin")), "Please run npm install.");
        Assertion::notEmpty(trim(shell_exec("which $nodeModulesBin/mozjpeg")), "Please run npm install.");
        Assertion::notEmpty(trim(shell_exec("which $nodeModulesBin/pngquant")), "Please run npm install.");
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     * @throws AssertionFailedException
     */
    public function __invoke(InputInterface $input, OutputInterface $output)
    {
        $this->setHelperSet(new HelperSet(['question' => new QuestionHelper]));

        // create some whitespace
        $this->createWhitespace($output);

        // We want a mapping of every image to its last modified time. We will then do the same with the
        // already optimized directory (if it exists) and compare them so that we can keep the optimized
        // directory in sync with the raw images
        $onlyInclude = $input->getOption('only-include');
        $rawImages = $this->mapImagesInDirectory($this->inputDirectory, $onlyInclude);
        $optimizedImages = $this->mapImagesInDirectory($this->outputDirectory, $onlyInclude, true);

        // TODO get total file size of all raw images, and ensure the system has at least enough disk space for that amount plus some padding


        // 1) optimize new raw images
        $newImages = array_filter($rawImages, function (array $rawImageInfo) use ($optimizedImages) {
            return !array_key_exists($rawImageInfo['source_filepath_hash'], $optimizedImages);
        });
        // 2) optimize already optimized images whose raw counterpart has been modified
        $modifiedImages = array_filter($rawImages, function (array $rawImageInfo) use ($optimizedImages) {
            if (!array_key_exists($rawImageInfo['source_filepath_hash'], $optimizedImages)) {
                return false;
            }

            $optimizedImageMTime = $optimizedImages[$rawImageInfo['source_filepath_hash']]['mtime'];

            return (int) $optimizedImageMTime < (int) $rawImageInfo['mtime'];
        });
        // 3) delete any optimized images that no longer exist as raw images
        $deletedImages = array_filter($optimizedImages, function (array $optimizedImageInfo) use ($rawImages) {
            return !array_key_exists($optimizedImageInfo['source_filepath_hash'], $rawImages);
        });

        $output->writeln(sprintf("<info>Need to optimize %s new images.</info>", count($newImages)));
        $output->writeln(sprintf("<info>Need to re-optimize %s images.</info>", count($modifiedImages)));
        $output->writeln(sprintf("<info>Need to delete %s images.</info>", count($deletedImages)));

        if (empty(array_merge($newImages, $modifiedImages, $deletedImages))) {
            $output->writeln("<comment>Nothing to do.</comment>");
            return;
        }

        $this->createWhitespace($output);

        if ($input->getOption('yes') === false) {
            if (!$this->getHelper('question')->ask($input, $output, new ConfirmationQuestion("<question>Do you want to proceed? (y|n)</question>", false))) {
                $this->createWhitespace($output);
                $output->writeln("<comment>Not proceeding.</comment>");
                return false;
            }
        }

        $imagesToOptimize = array_merge($newImages, $modifiedImages);

        $chunks = array_chunk($imagesToOptimize, self::CHUNK_SIZE);

        // copy all images into a temp directory using the hash as the filename
        $parentDir = sys_get_temp_dir() . '/temporary-optimized-images_' . uniqid();
        mkdir($parentDir);

        foreach ($chunks as $index => $imagesInChunk) {
            $dir = $parentDir . "/$index";

            // make the directory for this chunk
            mkdir($dir);

            // now loop thru and copy each image in this chunk
            foreach ($imagesInChunk as $imageInfo) {
                $pathInfo = pathinfo($imageInfo['filepath']);

                $tempPath = $dir . '/' . $imageInfo['source_filepath_hash'] . '.' . $pathInfo['extension'];

                copy($imageInfo['filepath'], $tempPath);
            }

            // optimize all images in this chunk in place
            shell_exec("./node_modules/.bin/imagemin --plugin=mozjpeg --plugin=pngquant --plugin=gifsicle --plugin=svgo $dir/* --out-dir=$dir");

            // now that all the images are optimized, we need to restore their original directory structure/filename and place them in the output directory
            foreach ($imagesInChunk as $imageInfo) {
                $pathInfo = pathinfo($imageInfo['filepath']);

                $tempPath = $dir . '/' . $imageInfo['source_filepath_hash'] . '.' . $pathInfo['extension'];

                $directory = $this->outputDirectory . $pathInfo['dirname'];

                if (!file_exists($directory)) {
                    mkdir($directory, 0777, true);
                }

                $filename = $directory . '/' . $pathInfo['basename'];

                rename($tempPath, $filename);
            }

            // all images optimized and placed in the output directory, remove the current chunk directory
            rmdir($dir);
        }

        // all chunks processed, remove the parent directory
        rmdir($parentDir);


        if ($input->getOption('no-delete') === false) {
            foreach ($deletedImages as $deletedImageInfo) {
                // don't forget to prefix the output directory
                $optimizedImage = $deletedImageInfo['filepath'];

                $output->writeln("<comment>Removing optimized image that no longer exists: $optimizedImage</comment>");

                // ensure we don't accidentally remove anything outside the output directory
                Assertion::contains($optimizedImage, $this->outputDirectory);
                unlink($optimizedImage);
            }
        }

        $this->createWhitespace($output);
        $output->writeln("<info>Finished optimizing images.</info>");
        $this->createWhitespace($output);
    }

    /**
     * @param string $directory
     * @param array $onlyInclude
     * @param bool $isOutputDirectory
     * @return array
     */
    private function mapImagesInDirectory(string $directory, array $onlyInclude = [], bool $isOutputDirectory = false): array
    {
        $result = explode(PHP_EOL, trim(shell_exec("find '$directory' -type f -iregex '.*\.\(jpg\|gif\|png\|svg\|jpeg\)$' -exec ls -l --time-style=+%s {} +")));

        // remove empty string on initial run
        $result = array_filter($result);

        $map = [];

        foreach ($result as $fileInfo) {
            // we want to discard some leading output, and be left with only "filesize mtime filepath"
            $fileInfo = trim(preg_replace('/^[^\s]+\s+[^\s]+\s+[^\s]+\s+[^\s]+\s+/', '', $fileInfo));

            // break up the value by spaces, then slice off the parts we need
            $parts = explode(' ', $fileInfo);

            $filesize = $parts[0];
            $mtime = $parts[1];
            $filepath = implode(' ', array_slice($parts, 2)); // we need to implode in case there were spaces somewhere in the path

            $hash = md5($filepath);

            // if this file in inside the output directory, we need to get the source hash
            // by excluding the leading output directory string
            if ($isOutputDirectory) {
                $regex = preg_quote($this->outputDirectory, '/');

                $hash = md5(preg_replace("/^$regex/", '', $filepath));
            }

            // if the user specified to only include certain files, don't include any filepath
            // that does not contain the search string
            if (!empty($onlyInclude)) {
                $found = false;

                foreach ($onlyInclude as $item) {
                    if (strpos($filepath, $item) !== false) {
                        $found = true;
                    }
                }

                if (!$found) {
                    // continue, so it doesn't get added to the $map
                    continue;
                }
            }

            $map[$hash] = [
                'source_filepath_hash' => $hash,
                'filepath' => $filepath,
                'filesize' => $filesize,
                'mtime' => $mtime,
            ];
        }

        return $map;
    }

    /**
     * @param OutputInterface $output
     */
    private function createWhitespace(OutputInterface $output)
    {
        $output->writeln("");
        $output->writeln("");
        $output->writeln("");
    }
}
