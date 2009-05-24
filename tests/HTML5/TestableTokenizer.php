<?php

class HTML5_TestableTokenizer extends HTML5_Tokenizer
{
    public $outputTokens = array();
    private $_contentModelFlag;
    private $_lastStartFlag;

    // this interface does not match HTML5_Tokenizer's. It might make
    // more sense though
    public function __construct($data, $contentModelFlag, $lastStartFlag = null) {
        parent::__construct($data);
        $this->_contentModelFlag = $contentModelFlag;
        $this->_lastStartFlag = $lastStartFlag;
    }
    public function parse() {
        $this->content_model = $this->_contentModelFlag;
        if ($this->_lastStartFlag) {
            $this->token = array(
                'type' => self::STARTTAG,
                'name' => $this->_lastStartFlag,
            );
        }
        return parent::parse();
    }
    // --end mismatched interface

    protected function emitToken($token, $checkStream = true) {
        if ($checkStream) {
            // Emit errors from input stream.
            while ($this->stream->errors) {
                $this->emitToken(array_shift($this->stream->errors), false);
            }
        }
                //var_dump($token);
        
        // tree handling code omitted
        switch ($token['type']) {
            case self::DOCTYPE:
                if (!isset($token['name'])) $token['name'] = null;
                if (!isset($token['public'])) $token['public'] = null;
                if (!isset($token['system'])) $token['system'] = null;
                $this->outputTokens[] = array('DOCTYPE', $token['name'], $token['public'], $token['system'], empty($token['force-quirks']));
                break;
            case self::STARTTAG:
                $attr = new stdclass();
                foreach ($token['attr'] as $keypair) {
                    // XXX this is IMPORTANT behavior, check if it's
                    // in TreeConstructer
                    $name = $keypair['name'];
                    if (isset($attr->$name)) continue;
                    $attr->$name = $keypair['value'];
                }
                $start = array('StartTag', $token['name'], $attr);
                if (isset($token['self-closing'])) $start[] = true;
                $this->outputTokens[] = $start;
                break;
            case self::ENDTAG:
                $this->outputTokens[] = array('EndTag', $token['name']);
                // this is logic in the parent emitToken algorithm, but
                // for optimization reasons we haven't factored it out
                $this->content_model = self::PCDATA;
                break;
            case self::COMMENT:
                $this->outputTokens[] = array('Comment', $token['data']);
                break;
            case self::CHARACTER:
                if (count($this->outputTokens)) {
                    $old = array_pop($this->outputTokens);
                    if ($old[0] === 'Character') {
                        $old[1] .= $token['data'];
                        $this->outputTokens[] = $old;
                        break;
                    }
                    $this->outputTokens[] = $old;
                }
                $this->outputTokens[] = array('Character', $token['data']);
                break;
            case self::PARSEERROR:
                $this->outputTokens[] = 'ParseError';
                break;
        }
    }
}
