#!/usr/bin/env php
<?php
/**
 * File containing the qatools-php file.
 *
 * @version //autogentag//
 * @package QATools
 * @copyright Copyright (C) 2012 Guillaume Kulakowski and contributors
 * @license http://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0
 */

require dirname( __FILE__ ) . '/../lib/bootstrap.php'; // Packagers "sed" it !


// Init ConsoleTools
$ct = qatConsoleTools::getInstance();
$ct->addOptionOutput();
$ct->addOptionIncludeFilters( array( '@\.php$@' ) );
$ct->addOptionExcludeFilters();
$ct->addOptionAllowCRLF();
$ct->addOptionAllowLineAfterTag();
$ct->addArgSource();

// Process
$ct->process();
$ct->output->outputLine();


// Go tests
$testSuites = qatJunitXMLTestSuites::getInstance();

foreach ( $ct->findRecursiveFromArg() as $file )
{
    $ct->output->outputLine( $file );

    $testSuite = $testSuites->addTestSuite();
    $testSuite->setName( 'Check PHP files' );
    $testSuite->setFile( __FILE__ );

    $contentFile = file_get_contents( $file );

    qatTestPhp::checkCRLF( $testSuite, $file, $contentFile );
    qatTestPhp::checkEncoding( $testSuite, $file, $contentFile );
    qatTestPhp::checkBOM( $testSuite, $file, $contentFile );
    qatTestPhp::checkLineAfterFinalTag( $testSuite, $file, $contentFile );

    $contentFile = null;
    $testSuite->finish();
}

$ct->output->outputLine( "\n" . 'Checked.' );

file_put_contents( $ct->options['output']->value, $testSuites->getXML() );

?>