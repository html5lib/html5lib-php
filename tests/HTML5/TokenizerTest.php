<?php

require_once dirname(__FILE__) . '/../autorun.php';

SimpleTest::ignore('HTML5_TokenizerHarness');
abstract class HTML5_TokenizerHarness extends HTML5_JSONHarness
{
    public function invoke($test) {
        //echo get_class($this) . ': ' . $test->description ."\n";
        if (!isset($test->contentModelFlags)) {
            $test->contentModelFlags = array('PCDATA');
        }
        foreach ($test->contentModelFlags as $flag) {
            $result = $this->tokenize($test, $flag);
            $expect = array();
            $last = null;
            foreach ($test->output as $tok) {
                // Normalize character tokens from the test
                if ($tok[0] === 'Character' && $last[0] === 'Character') {
                    $last[1] .= $tok[1];
                    continue;
                }
                // XXX we should also normalize our results... somewhere
                // (probably not here)
                $expect[] = $tok;
                $last =& $expect[count($expect) - 1];
            }
            $this->assertIdentical($expect, $result,
                'In test "'.str_replace('%', '%%', $test->description).
                '" with content model '.$flag.': %s'
            );
            if ($expect != $result) {
                echo "Input: "; str_dump($test->input);
                echo "Expected: "; var_dump($expect);
                echo "Actual: "; var_dump($result);
            }
        }
    }
    public function tokenize($test, $flag) {
        $flag = constant("HTML5_Tokenizer::$flag");
        if (!isset($test->lastStartTag)) $test->lastStartTag = null;
        $tokenizer = new HTML5_TestableTokenizer($test->input, $flag, $test->lastStartTag);
        $GLOBALS['TIME'] -= get_microtime();
        $tokenizer->parse();
        $GLOBALS['TIME'] += get_microtime();
        return $tokenizer->outputTokens;
    }
}

// generate test suites for tokenizer
HTML5_TestData::generateTestCases(
    'HTML5_TokenizerHarness',
    'HTML5_TokenizerTestOf',
    'tokenizer', '*.test'
);
