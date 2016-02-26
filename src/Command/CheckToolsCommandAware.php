<?php

/*
 * This file is part of the CheckToolsFramework package.
 *
 * Copyright (C) 2015-2016 Guillaume Kulakowski <guillaume@kulakowski.fr>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Llaumgui\CheckToolsFramework\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Llaumgui\JunitXml\JunitXmlTestSuites;
use Llaumgui\CheckToolsFramework\CheckTool\CheckToolInterface;

/**
 * Check class with all default configuration shared between each CheckToolsCommand.
 * Expose also some check helpers.
 */
abstract class CheckToolsCommandAware extends Command
{
    /**
     * Command argument: path.
     * @var array
     */
    protected $path;
    /**
     * Command argument: --path-exclusion.
     * @var string
     */
    protected $pathPaternExclusion;
    /**
     * Command argument: --output.
     * @var string
     */
    protected $outputFile;
    /**
     * Command argument: --filename.
     * @var string
     */
    protected $fileNamePatern = "*";
    /**
     * Command argument: --filename-exclusion.
     * @SuppressWarnings(PHPMD.LongVariable)
     * @var string
     */
    protected $fileNamePaternExclusion;
    /**
     * Command argument: --noignore-vcs.
     * @var boolean
     */
    protected $ignoreVcs = true;
    /**
     * The Finder use by command.
     * @var Symfony\Component\Finder\Finder
     */
    protected $finder;
    /**
     * Output interface.
     * @var Symfony\Component\Console\Output\OutputInterface
     */
    protected $output;
    /**
     * Number of error(s).
     * @var integer
     */
    protected $numError;
    /**
     * CheckTool used by command.
     * @var Llaumgui\CheckToolsFramework\CheckTool\CheckToolInterface
     */
    private $checkTool;
    /**
     * JunitXmlTestSuites generated by command.
     * @var Llaumgui\JunitXml\JunitXmlTestSuites
     */
    private $testSuites;


    /**
     * Configures the child command.
     */
    protected function configure()
    {
        $this
            ->addArgument(
                'path',
                InputArgument::REQUIRED | InputArgument::IS_ARRAY,
                'Path where to find files.' . "\n"
                . 'Can have mutliple values and can use string or regular expression.'
            )
            ->addOption(
                '--filename',
                '-f',
                InputOption::VALUE_REQUIRED,
                'File name pattern to check (can use regular expression)',
                $this->fileNamePatern
            )
            ->addOption(
                '--output',
                '-o',
                InputOption::VALUE_OPTIONAL,
                'Junit XML ouput',
                $this->outputFile
            )
            ->addOption(
                '--filename-exclusion',
                null,
                InputOption::VALUE_OPTIONAL,
                'File name pattern extension (can use regular expression)'
            )
            ->addOption(
                '--path-exclusion',
                null,
                InputOption::VALUE_OPTIONAL,
                'Directory name pattern extension (can use regular expression)'
            )
            ->addOption(
                '--noignore-vcs',
                null,
                InputOption::VALUE_NONE,
                'By default the finder ignore VCS files and directories'
            )
        ;
    }


    /**
     * Executes the current command.
     *
     * This method is not abstract because you can use this class
     * as a concrete class. In this case, instead of defining the
     * execute() method, you set the code to execute by passing
     * a Closure to the setCode() method.
     *
     * @param InputInterface  $input  An InputInterface instance
     * @param OutputInterface $output An OutputInterface instance
     *
     * @return null|int null or 0 if everything went fine, or an error code
     *
     * @throws \LogicException When this abstract method is not implemented
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Init CheckToolsCommand
        $this->path = $input->getArgument('path');
        $this->outputFile = $input->getOption('output');
        $this->pathPaternExclusion = $input->getOption('path-exclusion');
        $this->fileNamePatern = $input->getOption('filename');
        $this->fileNamePaternExclusion = $input->getOption('filename-exclusion');
        $this->ignoreVcs = ($input->getOption('noignore-vcs') ? false : true);
        $this->output = $output;

        return $this->doExecute();
    }


    /**
     * Do the execution of the command.
     *
     * @return null|int null or 0 if everything went fine, or an error code
     */
    protected function doExecute()
    {
        // Init Junit log
        $checkTool = $this->getCheckTool();
        $this->testSuites = new JunitXmlTestSuites($checkTool->getTestSuitesDescription());
        $testSuite = $this->testSuites->addTestSuite($checkTool->getTestSuiteDescription());

        // Do check in files from Finder
        foreach ($this->getFinder() as $file) {
            $check = $checkTool->doCheck($file);

            // Create TestCase
            $testCase = $testSuite->addTest($check->getDescription());
            $testCase->setClassName($file->getRelativePathname());
            $testCase->incAssertions();

            if (!$check->getResult()) {
                $this->output->writeln($check->getDescription() . ': <error>Failed</error>');
                $testCase->addError($check->getMessage());

                // Count error
                $this->numError++;
            } elseif ($check->getResult() && $this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $this->output->writeln($check->getDescription() . ': <info>Succeeded</info>');
            }
            $testCase->finish();
        }
        $testSuite->finish();

        return $this->postExecuteHook();
    }


    /**
     * Get list of files.
     *
     * @return Symfony\Component\Finder\Finder The finder with the list of files.
     */
    protected function getFinder()
    {
        // Set finder
        $finder = new Finder();
        $finder
            ->in($this->path)
            ->files()->name($this->fileNamePatern)
            ->ignoreVCS($this->ignoreVcs);

        if (!empty($this->fileNamePaternExclusion)) {
            $finder->files()->notName($this->fileNamePaternExclusion);
        }
        if (!empty($this->pathPaternExclusion)) {
            $finder->files()->notPath($this->pathPaternExclusion);
        }

        return $finder;
    }


    /**
     * Post check hook called at the end of a check.
     *
     * @return int The command exit code.
     */
    protected function postExecuteHook()
    {
        $this->writeOutput();

        if ($this->numError > 0) {
            return 1;
        } else {
            return 0;
        }
    }


    /**
     * Output test result.
     */
    protected function writeOutput()
    {
        if (!empty($this->outputFile)) {
            $fileSystem = new Filesystem();
            try {
                $fileSystem->dumpFile($this->outputFile, $this->testSuites->getXml());
            } catch (IOExceptionInterface $e) {
                $this->output->writeln('<info>Error writing in ' . $this->outputFile . '</info>');
            }
        }
    }


    /**
     * CheckTool getter.
     *
     * @return Llaumgui\CheckToolsFramework\CheckTool\CheckToolInterface
     */
    public function getCheckTool()
    {
        return $this->checkTool;
    }


    /**
     * checkTool setter.
     *
     * @param CheckToolInterface $checkTool
     */
    public function setCheckTool(CheckToolInterface $checkTool)
    {
        $this->checkTool = $checkTool;
    }
}
