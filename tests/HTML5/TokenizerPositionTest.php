<?php

require_once dirname(__FILE__) . '/../autorun.php';

class HTML5_PositionTestableTokenizer extends HTML5_TestableTokenizer
{
    public $outputLines = array();
    public $outputCols  = array();
    protected function emitToken($token) {
        parent::emitToken($token);
        $this->outputLines[] = $this->getCurrentLine();
        $this->outputCols[]  = $this->getColumnOffset();
    }
}

class HTML5_TokenizerTestOfPosition extends UnitTestCase
{
    function testBasic() {
        $this->assertPositions(
            "<b><i>f<p>\n<b>a</b>",
            array(1,1,1,1, 2,2,2,2),
            array(3,6,7,10,0,3,4,8)
        );
    }
    
    function testUnicode() {
        $this->assertPositions(
            "\xC2\xA2<b>\xE2\x82\xACa<b>\xf4\x8a\xaf\x8d",
            array(1,1,1,1,1),
            array(1,4,6,9,10)
        );
    }
    
    function testData() {
        $this->assertPositions(
            "a\na\n\xC2\xA2<b>",
            array(3,3),
            array(1,4)
        );
    }
    
    function testMarkupDeclarationDoubleDash() {
        $this->assertPositions(
            '<!-- foo -->',
            array(1),
            array(12)
        );
    }
    
    function testMarkupDeclarationDoctype() {
        $this->assertPositions(
            '<!DOCTYPE>',
            array(1),
            array(10)
        );
    }
    
    function testAfterDoctypeNamePublic() {
        $this->assertPositions(
            '<!DOCTYPE PUBLIC "foo">',
            array(1),
            array(23)
        );
    }
    
    function testAfterDoctypeNameSystem() {
        $this->assertPositions(
            '<!DOCTYPE SYSTEM "foo">',
            array(1),
            array(23)
        );
    }
    
    function testDecEntitySansSemicolon() {
        $this->assertPositions(
            '&#300',
            array(1),
            array(5)
        );
    }
    
    function testDecEntityWithSemicolon() {
        $this->assertPositions(
            '&#300;',
            array(1),
            array(6)
        );
    }
    
    function testHexEntity() {
        $this->assertPositions(
            '&#x300;',
            array(1),
            array(7)
        );
    }
    
    function testEmptyEntity() {
        $this->assertPositions(
            '&#;<b>',
            // note that the ampersand and the #; are not in the
            // same token. This is slightly implementation dependent;
            // it might be a good idea to buffer text tokens
            array(1,1,1),
            array(1,3,6)
        );
    }
    
    function testNamedEntity() {
        $this->assertPositions(
            '&quot;foo<b>',
            array(1,1,1),
            array(6,9,12)
        );
    }
    
    function testBadNamedEntity() {
        $this->assertPositions(
            '&zzz;b',
            array(1,1),
            array(1,6) // ampersand!
        );
    }
    
    function testAttributeEntity() {
        $this->assertPositions(
            '<b foo="&amper">a',
            array( 1, 1),
            array(16,17)
        );
    }
    
    function testBogusComment() {
        $this->assertPositions(
            "<!as asdfe \nasdf>d",
            array(2,2),
            array(5,6)
        );
    }
    
    protected function assertPositions($input, $lines, $cols, $flag = HTML5_Tokenizer::PCDATA, $lastStartTag = null) {
        $tokenizer = new HTML5_PositionTestableTokenizer($input, $flag, $lastStartTag);
        $GLOBALS['TIME'] -= get_microtime();
        $tokenizer->parse($input);
        $GLOBALS['TIME'] += get_microtime();
        $this->assertIdentical($tokenizer->outputLines, $lines, 'Lines: %s');
        $this->assertIdentical($tokenizer->outputCols, $cols,   'Cols: %s');
    }
}
