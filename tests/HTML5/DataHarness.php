<?php

/**
 * Modified test-case supertype for running tests that are not
 * test method based, but based off of test data that resides in
 * files.
 */
SimpleTest::ignore('HTML5_DataHarness');
abstract class HTML5_DataHarness extends UnitTestCase
{
    /**
     * Filled in by HTML5_TestData::generateTestCases()
     */
    protected $filename;
    /**
     * Invoked by the runner, it is the function responsible for executing
     * the test and delivering results.
     * @param $test Some easily usable representation of the test
     */
    abstract public function invoke($test);
    /**
     * Returns a list of tests that can be executed. The list members will
     * be passed to invoke(). Return an iterator if you don't want to load
     * all test into memory
     */
    abstract public function getDataTests();
    /**
     * Returns a description of the test
     */
    abstract public function getDescription($test);
    public function run($reporter) {
        // this is all duplicated code, kinda ugly
        // no skip support
        $context = SimpleTest::getContext();
        $context->setTest($this);
        $context->setReporter($reporter);
        $this->reporter = $reporter;
        $started = false;
        foreach ($this->getDataTests() as $test) {
            if ($reporter->shouldInvoke($this->getLabel(), $this->getDescription($test))) {
                if (! $started) {
                    $reporter->paintCaseStart($this->getLabel());
                    $started = true;
                }
                // errors are not trapped
                $this->invoke($test);
            }
        }
        if ($started) {
            $reporter->paintCaseEnd($this->getLabel());
        }
        unset($this->reporter);
        return $reporter->getStatus();
    }
}
