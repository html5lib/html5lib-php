<?php

require_once dirname(__FILE__) . '/../autorun.php';

class HTML5_TestDataTest extends UnitTestCase
{
    function testSample() {
        $data = new HTML5_TestData(dirname(__FILE__) . '/TestDataTest/sample.dat');
        $this->assertIdentical($data->tests, array(
            array('data' => "Foo\n", 'des' => "Bar\n"),
            array('data' => "Foo\n")
        ));
    }
}

