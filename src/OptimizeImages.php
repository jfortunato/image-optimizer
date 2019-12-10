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

    /**
     * @var string
     */
    private $outputDirectory = '';

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

        try {
            // assert that the system has all required shell commands
            $nodeModulesBin = self::NODE_MODULES_BIN;
            Assertion::notEmpty(trim(shell_exec('which npm')), "Please install npm");
            Assertion::notEmpty(trim(shell_exec("which $nodeModulesBin/imagemin")), "Please run npm install.");
            Assertion::notEmpty(trim(shell_exec("which $nodeModulesBin/mozjpeg")), "Please run npm install.");
            Assertion::notEmpty(trim(shell_exec("which $nodeModulesBin/pngquant")), "Please run npm install.");

            $inputDirectory = realpath(rtrim($input->getArgument('input-directory'), '/'));
            $outputDirectory = realpath(rtrim($input->getArgument('output-directory'), '/'));
            $this->outputDirectory = $outputDirectory;

            Assertion::directory($inputDirectory, 'Please make sure the input directory exists before running this script.');
            Assertion::directory($outputDirectory, 'Please make sure the output directory exists before running this script.');
        } catch (\Exception $e) {
            return $output->writeln($e->getMessage());
        }

        // We want a mapping of every image to its last modified time. We will then do the same with the
        // already optimized directory (if it exists) and compare them so that we can keep the optimized
        // directory in sync with the raw images
        $onlyInclude = $input->getOption('only-include');
        $rawImages = $this->mapImagesInDirectory($inputDirectory, $onlyInclude);
        $optimizedImages = $this->mapImagesInDirectory($outputDirectory, $onlyInclude, true);

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

        // copy all images into a temp directory using the hash as the filename
        $dir = sys_get_temp_dir() . '/temporary-optimized-images_' . uniqid();
        mkdir($dir);

        foreach ($imagesToOptimize as $imageInfo) {
            $tempPath = $dir . '/' . $imageInfo['source_filepath_hash'] . '.' . $imageInfo['extension'];

            copy($imageInfo['filepath'], $tempPath);
        }

        // optimize all images in the directory in place
        shell_exec("./node_modules/.bin/imagemin --plugin=mozjpeg --plugin=pngquant --plugin=gifsicle --plugin=svgo $dir/* --out-dir=$dir");

        // now that all the images are optimized, we need to restore their original directory structure/filename and place them in the output directory
        foreach ($imagesToOptimize as $imageInfo) {
            $tempPath = $dir . '/' . $imageInfo['source_filepath_hash'] . '.' . $imageInfo['extension'];

            $directory = $outputDirectory . $imageInfo['dirname'];

            if (!file_exists($directory)) {
                mkdir($directory, 0777, true);
            }

            $filename = $directory . '/' . $imageInfo['basename'];

            rename($tempPath, $filename);
        }

        rmdir($dir);


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
        $result = explode(PHP_EOL, trim(shell_exec("find $directory -iregex '.*\.\(jpg\|gif\|png\|svg\|jpeg\)$' -exec ls -l --time-style=+%s {} +")));

        // remove empty string on initial run
        $result = array_filter($result);

        $images = array_map(function (string  $fileInfo) use ($isOutputDirectory) {
            // replace multiple spaces with one space
            $fileInfo = preg_replace('/\s\s+/', ' ', $fileInfo);

            $parts = explode(' ', $fileInfo);

            $filepath = $parts[6];

            $hash = md5($filepath);

            // if this file in inside the output directory, we need to get the source hash
            // by excluding the leading output directory string
            if ($isOutputDirectory) {
                $regex = preg_quote($this->outputDirectory, '/');

                $hash = md5(preg_replace("/^$regex/", '', $filepath));
            }

            return array_merge([
                'source_filepath_hash' => $hash,
                'filepath' => $filepath,
                'filesize' => $parts[4],
                'mtime' => $parts[5],
            ], pathinfo($filepath));
        }, $result);

        $images = array_filter($images, function (array $imageInfo) use ($onlyInclude) {

            // if the user specified to only include certain files, don't include any filepath
            // that does not contain the search string
            if (!empty($onlyInclude)) {
                $found = false;

                foreach ($onlyInclude as $item) {
                    if (strpos($imageInfo['filepath'], $item) !== false) {
                        $found = true;
                    }
                }

                if (!$found) {
                    return false;
                }
            }

            return true;
        });

        // now re-index using the hash as the key
        $map = [];

        foreach ($images as $imageInfo) {
            $map[$imageInfo['source_filepath_hash']] = $imageInfo;
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
