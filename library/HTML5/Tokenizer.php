<?php

/*

Copyright 2007 Jeroen van der Meer <http://jero.net/>
Copyright 2008 Edward Z. Yang <http://htmlpurifier.org/>
Copyright 2009 Geoffrey Sneddon <http://gsnedders.com/>

Permission is hereby granted, free of charge, to any person obtaining a 
copy of this software and associated documentation files (the 
"Software"), to deal in the Software without restriction, including 
without limitation the rights to use, copy, modify, merge, publish, 
distribute, sublicense, and/or sell copies of the Software, and to 
permit persons to whom the Software is furnished to do so, subject to 
the following conditions: 

The above copyright notice and this permission notice shall be included 
in all copies or substantial portions of the Software. 

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS 
OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF 
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. 
IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY 
CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, 
TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE 
SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE. 

*/

// Some conventions:
// /* */ indicates verbatim text from the HTML 5 specification
// // indicates regular comments

// all flags are in hyphenated form

// Global optimizations to take note of (i.e., if you fix a bug in
// one optimization somewhere, fix it everywhere else)
// Optimization comments are marked with OOO

// AUTO-CONSUMPTION (i.e. THE TUBERCULOSIS HACK)
// For all states, a character is automatically consumed and placed
// in $this->c. Thus, you will see an assignment $char = $this->char
// in place of the usual $this->char++. In the event that a state
// doesn't normally consume a character, we decrement $this->char
// and refuse to use $this->c. This is VERY IMPORTANT, otherwise
// the mapping of spec to code won't really make sense.
// We also have a $noConsumeStates variable, for telling the loop
// not to consume characters in the rare cases it isn't helpful.

// Reprocess token
// Instead of decrementing the char pointer and letting the loop do
// its thing, we simply directly invoke the state we want to reprocess
// the character in. The same goes for EOF, except we directly invoke
// the EOF() method.

class HTML5_Tokenizer {
    /**
     * The string data we're parsing.
     */
    private $data;
    
    /**
     * The current integer byte position we are in $data
     */
    private $char;
    
    /**
     * Length of $data; when $char === $data, we are at the end-of-file.
     */
    private $EOF;
    
    /**
     * String state to execute.
     */
    private $state;
    
    /**
     * Tree builder that the tokenizer emits token to.
     */
    private $tree;
    
    /**
     * Escape flag as specified by the HTML5 specification: "used to
     * control the behavior of the tokeniser. It is either true or
     * false, and initially must be set to the false state."
     */
    private $escape = false;
    
    /**
     * The value of the auto-consumed byte. See AUTO-CONSUMPTION.
     */
    private $c;
        
    /**
     * Current content model we are parsing as.
     */
    protected $content_model;
    
    /**
     * Current token that is being built, but not yet emitted. Also
     * is the last token emitted, if applicable.
     */
    protected $token;

    /**
     * States that should not have AUTO-CONSUMPTION applied.
     */
    private $noConsumeStates = array(
        'characterReferenceData' => true,
        'bogusComment' => true,
    );

    // These are constants describing the content model
    const PCDATA    = 0;
    const RCDATA    = 1;
    const CDATA     = 2;
    const PLAINTEXT = 3;

    // These are constants describing tokens
    // XXX should probably be moved somewhere else, probably the
    // HTML5 class.
    const DOCTYPE  = 0;
    const STARTTAG = 1;
    const ENDTAG   = 2;
    const COMMENT  = 3;
    const CHARACTER = 4;
    const EOF      = 5;

    /**
     * @param $data Data to parse
     */
    public function __construct($data) {
        
        // XXX this is actually parsing stuff
        
        /* Given an encoding, the bytes in the input stream must be
        converted to Unicode characters for the tokeniser, as
        described by the rules for that encoding, except that the
        leading U+FEFF BYTE ORDER MARK character, if any, must not
        be stripped by the encoding layer (it is stripped by the rule below).

        Bytes or sequences of bytes in the original byte stream that
        could not be converted to Unicode characters must be converted
        to U+FFFD REPLACEMENT CHARACTER code points. */
        
        // XXX currently assuming input data is UTF-8; once we
        // build encoding detection this will no longer be the case
        //
        // We previously had an mbstring implementation here, but that
        // implementation is heavily non-conforming, so it's been
        // omitted.
        if (function_exists('iconv')) {
            // non-conforming
            $data = iconv('UTF-8', 'UTF-8//IGNORE', $data);
        } else {
            // we can make a conforming native implementation
            throw new Exception('Not implemented, please install mbstring or iconv');
        }

        /* One leading U+FEFF BYTE ORDER MARK character must be
        ignored if any are present. */
        if (substr($data, 0, 3) === "\xEF\xBB\xBF") {
            $data = substr($data, 3);
        }
        
        /* All U+0000 NULL characters in the input must be replaced
        by U+FFFD REPLACEMENT CHARACTERs. Any occurrences of such
        characters is a parse error. */
        /* U+000D CARRIAGE RETURN (CR) characters and U+000A LINE FEED
        (LF) characters are treated specially. Any CR characters
        that are followed by LF characters must be removed, and any
        CR characters not followed by LF characters must be converted
        to LF characters. Thus, newlines in HTML DOMs are represented
        by LF characters, and there are never any CR characters in the
        input to the tokenization stage. */
        $data = strtr($data, array(
            "\0"   => "\xEF\xBF\xBD",
            "\r\n" => "\n",
            "\r"   => "\n"
        ));
        
        /* Any occurrences of any characters in the ranges U+0001 to
        U+0008, U+000B,  U+000E to U+001F,  U+007F  to U+009F,
        U+D800 to U+DFFF , U+FDD0 to U+FDEF, and
        characters U+FFFE, U+FFFF, U+1FFFE, U+1FFFF, U+2FFFE, U+2FFFF,
        U+3FFFE, U+3FFFF, U+4FFFE, U+4FFFF, U+5FFFE, U+5FFFF, U+6FFFE,
        U+6FFFF, U+7FFFE, U+7FFFF, U+8FFFE, U+8FFFF, U+9FFFE, U+9FFFF,
        U+AFFFE, U+AFFFF, U+BFFFE, U+BFFFF, U+CFFFE, U+CFFFF, U+DFFFE,
        U+DFFFF, U+EFFFE, U+EFFFF, U+FFFFE, U+FFFFF, U+10FFFE, and
        U+10FFFF are parse errors. (These are all control characters
        or permanently undefined Unicode characters.) */
        // XXX not implemented!
        // code for this exists later below, factor it out
        // The most efficient way of implementing this is probably
        // PCRE; strtr would work but have a high memory cost.

        $this->data = $data;
        $this->char = -1;
        $this->EOF  = strlen($data);
        $this->tree = new HTML5_TreeConstructer;
        $this->content_model = self::PCDATA;

        $this->state = 'data';
    }

    // XXX maybe convert this into an iterator? regardless, this function
    // and the save function should go into a Parser facade of some sort
    /**
     * Performs the actual parsing of the document.
     */
    public function parse() {
        while($this->state !== null) {
            //echo $this->state . "\n";
            if (!isset($this->noConsumeStates[$this->state])) {
                // consume a character, and assign it to $this->c
                $this->c = (++$this->char < $this->EOF) ? $this->data[$this->char] : false;
            }    
            // OOO get rid of this concatentation
            $this->{$this->state.'State'}();
            
        }
    }

    /**
     * Returns a serialized representation of the tree.
     */
    public function save() {
        return $this->tree->save();
    }

    /**
     * Returns the current line that the tokenizer is at.
     */
    public function getCurrentLine() {
        // Check the string isn't empty
        if($this->EOF) {
            // Add one to $this->char because we want the number for the next
            // byte to be processed.
            return substr_count($this->data, "\n", 0, $this->char + 1) + 1;
        } else {
            // If the string is empty, we are on the first line (sorta).
            return 1;
        }
    }

    /**
     * Returns the current column of the current line that the tokenizer is at.
     */
    public function getColumnOffset() {
        // strrpos is weird, and the offset needs to be negative for what we
        // want (i.e., the last \n before $this->char). This needs to not have
        // one (to make it point to the next character, the one we want the
        // position of) added to it because strrpos's behaviour includes the
        // final offset byte.
        $lastLine = strrpos($this->data, "\n", $this->char - strlen($this->data));
        
        // However, for here we want the length up until the next byte to be
        // processed, so add one to the current byte ($this->char).
        if($lastLine !== false) {
            $findLengthOf = substr($this->data, $lastLine + 1, $this->char - $lastLine);
        } else {
            $findLengthOf = substr($this->data, 0, $this->char + 1);
        }
        
        // Get the length for the string we need.
        if(extension_loaded('iconv')) {
            return iconv_strlen($findLengthOf, 'utf-8');
        } elseif(extension_loaded('mbstring')) {
            return mb_strlen($findLengthOf, 'utf-8');
        } elseif(extension_loaded('xml')) {
            return strlen(utf8_decode($findLengthOf));
        } else {
            $count = count_chars($findLengthOf);
            // 0x80 = 0x7F - 0 + 1 (one added to get inclusive range)
            // 0x33 = 0xF4 - 0x2C + 1 (one added to get inclusive range)
            return array_sum(array_slice($count, 0, 0x80)) +
                   array_sum(array_slice($count, 0xC2, 0x33));
        }
    }
    
    /**
     * Retrieve the currently consume character.
     * @note This performs bounds checking
     */
    private function char() {
        return ($this->char < $this->EOF)
            ? $this->data[$this->char]
            : false;
    }

    /**
     * Return some range of characters.
     * @note This performs bounds checking
     * @param $s Starting index
     * @param $l Length
     */
    private function character($s, $l = 1) {
        if($s + $l <= $this->EOF) {
            if($l === 1) {
                return $this->data[$s];
            } else {
                return substr($this->data, $s, $l);
            }
        }
    }
    
    /**
     * Advanced the character pointer by however many characters.
     * @note This performs bounds checking
     */
    private function seek($count) {
        for ($i = 0; $i < $count; $i++) {
            $this->char++;
            if ($this->char === $this->EOF) break;
        }
    }

    // OOO this is really inefficient; we should use strspn/strcspn
    // with constants representing A-Z, a-z and 0-9
    /**
     * Matches as far as possible from $start for a certain character class
     * and returns the matched substring.
     * @param $char_class PCRE compatible character class expression
     * @param $start Starting index to perform search
     */
    private function characters($char_class, $start) {
        preg_match('#^['.$char_class.']+#', substr($this->data, $start), $matches);
        return $matches ? $matches[0] : '';
    }

    private function dataState() {

        // Possible optimization: mark text tokens that contain entirely
        // whitespace as whitespace tokens.

        /* Consume the next input character */
        $char = $this->c;

        // see below for meaning
        $amp_cond =
            !$this->escape &&
            (
                $this->content_model === self::PCDATA ||
                $this->content_model === self::RCDATA
            );
        $lt_cond =
            $this->content_model === self::PCDATA ||
            (
                (
                    $this->content_model === self::RCDATA ||
                    $this->content_model === self::CDATA
                 ) &&
                 !$this->escape
            );
         
        if($char === '&' && $amp_cond) {
            /* U+0026 AMPERSAND (&)
            When the content model flag is set to one of the PCDATA or RCDATA
            states and the escape flag is false: switch to the
            character reference data state. Otherwise: treat it as per
            the "anything else" entry below. */
            $this->state = 'characterReferenceData';

        } elseif($char === '-') {
            /* If the content model flag is set to either the RCDATA state or
            the CDATA state, and the escape flag is false, and there are at
            least three characters before this one in the input stream, and the
            last four characters in the input stream, including this one, are
            U+003C LESS-THAN SIGN, U+0021 EXCLAMATION MARK, U+002D HYPHEN-MINUS,
            and U+002D HYPHEN-MINUS ("<!--"), then set the escape flag to true. */
            if(($this->content_model === self::RCDATA || $this->content_model ===
            self::CDATA) && $this->escape === false &&
            $this->char >= 3 && $this->character($this->char - 3, 4) === '<!--') {
                $this->escape = true;
            }

            /* In any case, emit the input character as a character token. Stay
            in the data state. */
            $this->emitToken(array(
                'type' => self::CHARACTER,
                'data' => $char
            ));

        /* U+003C LESS-THAN SIGN (<) */
        } elseif($char === '<' && $lt_cond) {
            /* When the content model flag is set to the PCDATA state: switch
            to the tag open state.

            When the content model flag is set to either the RCDATA state or
            the CDATA state and the escape flag is false: switch to the tag
            open state.

            Otherwise: treat it as per the "anything else" entry below. */
            $this->state = 'tagOpen';

        /* U+003E GREATER-THAN SIGN (>) */
        } elseif($char === '>') {
            /* If the content model flag is set to either the RCDATA state or
            the CDATA state, and the escape flag is true, and the last three
            characters in the input stream including this one are U+002D
            HYPHEN-MINUS, U+002D HYPHEN-MINUS, U+003E GREATER-THAN SIGN ("-->"),
            set the escape flag to false. */
            if(($this->content_model === self::RCDATA ||
            $this->content_model === self::CDATA) && $this->escape === true &&
            $this->char >= 2 && $this->character($this->char - 2, 3) === '-->') {
                $this->escape = false;
            }

            /* In any case, emit the input character as a character token.
            Stay in the data state. */
            $this->emitToken(array(
                'type' => self::CHARACTER,
                'data' => $char
            ));

        } elseif($char === false) {
            /* EOF
            Emit an end-of-file token. */
            $this->EOF();

        } elseif($this->content_model === self::PLAINTEXT) {
            // XXX it appears there is no such thing as a PLAINTEXT
            // state in the spec. ???
            /* When the content model flag is set to the PLAINTEXT state
            THIS DIFFERS GREATLY FROM THE SPEC: Get the remaining characters of
            the text and emit it as a character token. */
            $this->emitToken(array(
                'type' => self::CHARACTER,
                'data' => substr($this->data, $this->char)
            ));

            $this->EOF();

        } else {
            /* Anything else
            THIS IS AN OPTIMIZATION: Get as many character that
            otherwise would also be treated as a character token and emit it
            as a single character token. Stay in the data state. */
            // XXX The complexity of the extra code we would need
            // to insert for this optimization makes justifying this
            // for less used codepaths difficult.
            
            $mask = '->';
            if ($amp_cond) $mask .= '&';
            if ($lt_cond)  $mask .= '<';
            
            $len  = strcspn($this->data, $mask, $this->char + 1);
            $char = substr($this->data, $this->char + 1, $len);
                        
            $this->char += $len;

            $this->emitToken(array(
                'type' => self::CHARACTER,
                'data' => $this->c . $char
            ));

            $this->state = 'data';
        }
    }

    private function characterReferenceDataState() {
        /* (This cannot happen if the content model flag
        is set to the CDATA state.) */
        
        /* Attempt to consume a character reference, with no
        additional allowed character. */
        // XXX no additional allowed character?
        $entity = $this->consumeCharacterReference();

        /* If nothing is returned, emit a U+0026 AMPERSAND
        character token. Otherwise, emit the character token that
        was returned. */
        $char = !is_string($entity) ? '&' : $entity;
        $this->emitToken(array(
            'type' => self::CHARACTER,
            'data' => $char
        ));

        /* Finally, switch to the data state. */
        $this->state = 'data';
    }

    private function tagOpenState() {
        switch($this->content_model) {
            case self::RCDATA:
            case self::CDATA:
                /* Consume the next input character. If it is a
                U+002F SOLIDUS (/) character, switch to the close
                tag open state. Otherwise, emit a U+003C LESS-THAN
                SIGN character token and reconsume the current input
                character in the data state. */
                // optimization: perform a look-ahead and duplicated
                // data state here
                if($this->c === '/') {
                    $this->state = 'closeTagOpen';

                } else {
                    $this->emitToken(array(
                        'type' => self::CHARACTER,
                        'data' => '<'
                    ));

                    $this->state = 'data';
                    $this->dataState();
                }
            break;

            case self::PCDATA:
                /* If the content model flag is set to the PCDATA state
                Consume the next input character: */
                $char = $this->c;

                if($char === '!') {
                    /* U+0021 EXCLAMATION MARK (!)
                    Switch to the markup declaration open state. */
                    $this->state = 'markupDeclarationOpen';

                } elseif($char === '/') {
                    /* U+002F SOLIDUS (/)
                    Switch to the close tag open state. */
                    $this->state = 'closeTagOpen';

                } elseif('A' <= $char && $char <= 'Z') {
                    /* U+0041 LATIN LETTER A through to U+005A LATIN LETTER Z
                    Create a new start tag token, set its tag name to the lowercase
                    version of the input character (add 0x0020 to the character's code
                    point), then switch to the tag name state. (Don't emit the token
                    yet; further details will be filled in before it is emitted.) */
                    $this->token = array(
                        'name'  => strtolower($char),
                        'type'  => self::STARTTAG,
                        'attr'  => array()
                    );

                    $this->state = 'tagName';

                } elseif('a' <= $char && $char <= 'z') {
                    /* U+0061 LATIN SMALL LETTER A through to U+007A LATIN SMALL LETTER Z
                    Create a new start tag token, set its tag name to the input
                    character, then switch to the tag name state. (Don't emit
                    the token yet; further details will be filled in before it
                    is emitted.) */
                    $this->token = array(
                        'name'  => $char,
                        'type'  => self::STARTTAG,
                        'attr'  => array()
                    );

                    $this->state = 'tagName';

                } elseif($char === '>') {
                    /* U+003E GREATER-THAN SIGN (>)
                    Parse error. Emit a U+003C LESS-THAN SIGN character token and a
                    U+003E GREATER-THAN SIGN character token. Switch to the data state. */
                    $this->emitToken(array(
                        'type' => self::CHARACTER,
                        'data' => '<>'
                    ));

                    $this->state = 'data';

                } elseif($char === '?') {
                    /* U+003F QUESTION MARK (?)
                    Parse error. Switch to the bogus comment state. */
                    $this->state = 'bogusComment';

                } else {
                    /* Anything else
                    Parse error. Emit a U+003C LESS-THAN SIGN character token and
                    reconsume the current input character in the data state. */
                    $this->emitToken(array(
                        'type' => self::CHARACTER,
                        'data' => '<'
                    ));

                    $this->state = 'data';
                    $this->dataState();
                }
            break;
        }
    }

    private function closeTagOpenState() {
        $next_node = strtolower($this->characters('A-Za-z', $this->char));
        
        // due to the flow of the HTML 5 specification, $this->token
        // is guaranteed to be the last STARTTAG emitted (the other
        // possible values: ENDTAG, COMMENT and DOCTYPE, cannot be
        // possibly invoked in RCDATA or CDATA state). A similar
        // optimization is done in the Python implementation.
        if ($this->token && isset($this->token['name'])) {
            $the_same = ($this->token['name'] === $next_node);
        } else {
            // optimization: this accounts for an empty token case
            $the_same = false;
        }
        // optimization: we combined the content model checks:
        // i.e. what was (a && b) || (a && c) becomes a && (b || c)
        if(
            (
                /* If the content model flag is set to the
                RCDATA or CDATA states */
                $this->content_model === self::RCDATA ||
                $this->content_model === self::CDATA
            ) &&
            (
                /* but no start tag token has ever been emitted by this
                instance of the tokeniser (fragment case), [...] or */
                /* the next few characters do not match the tag name of
                the last start tag token emitted (compared in an
                ASCII case insensitive manner), */
                !$the_same ||
                (
                    /* if they do but they are not immediately followed
                    by one of the following characters:
                        * U+0009 CHARACTER TABULATION
                        * U+000A LINE FEED (LF)
                        * U+000C FORM FEED (FF)
                        * U+0020 SPACE
                        * U+003E GREATER-THAN SIGN (>)
                        * U+002F SOLIDUS (/)
                        * EOF */
                    $the_same &&
                    (
                        $this->EOF !== $this->char + strlen($next_node) &&
                        !preg_match(
                            '/[\t\n\x0b\x0c >\/]/',
                            $this->character(
                                $this->char + strlen($next_node)
                            )
                        )
                    )
                )
            )
        ) {
            /* ...then emit a U+003C LESS-THAN SIGN character token, a
            U+002F SOLIDUS character token, and switch to the data
            state to process the next input character. */
            $this->emitToken(array(
                'type' => self::CHARACTER,
                'data' => '</'
            ));

            $this->state = 'data';
            $this->dataState();

        } else {
            /* Otherwise, if the content model flag is set to the PCDATA state,
            or if the next few characters do match that tag name, consume the
            next input character: */
            $char = $this->c;

            if ('A' <= $char && $char <= 'Z') {
                /* U+0041 LATIN LETTER A through to U+005A LATIN LETTER Z
                Create a new end tag token, set its tag name to the lowercase version
                of the input character (add 0x0020 to the character's code point), then
                switch to the tag name state. (Don't emit the token yet; further details
                will be filled in before it is emitted.) */
                $this->token = array(
                    'name'  => strtolower($char),
                    'type'  => self::ENDTAG
                );

                $this->state = 'tagName';

            } elseif ('a' <= $char && $char <= 'z') {
                /* U+0061 LATIN SMALL LETTER A through to U+007A LATIN SMALL LETTER Z
                Create a new end tag token, set its tag name to the
                input character, then switch to the tag name state.
                (Don't emit the token yet; further details will be
                filled in before it is emitted.) */
                $this->token = array(
                    'name'  => $char,
                    'type'  => self::ENDTAG
                );

                $this->state = 'tagName';

            } elseif($char === '>') {
                /* U+003E GREATER-THAN SIGN (>)
                Parse error. Switch to the data state. */
                $this->state = 'data';

            } elseif($char === false) {
                /* EOF
                Parse error. Emit a U+003C LESS-THAN SIGN character token and a U+002F
                SOLIDUS character token. Reconsume the EOF character in the data state. */
                $this->emitToken(array(
                    'type' => self::CHARACTER,
                    'data' => '</'
                ));

                $this->state = 'data';
                $this->dataState();

            } else {
                /* Parse error. Switch to the bogus comment state. */
                $this->state = 'bogusComment';
            }
        }
    }

    private function tagNameState() {
        // Consume the next input character:
        $char = $this->c;

        if(preg_match('/^[\t\n\x0c ]$/', $char)) {
            /* U+0009 CHARACTER TABULATION
            U+000A LINE FEED (LF)
            U+000C FORM FEED (FF)
            U+0020 SPACE
            Switch to the before attribute name state. */
            $this->state = 'beforeAttributeName';

        } elseif($char === '/') {
            /* U+002F SOLIDUS (/)
            Switch to the self-closing start tag state. */
            $this->state = 'selfClosingStartTag';

        } elseif($char === '>') {
            /* U+003E GREATER-THAN SIGN (>)
            Emit the current tag token. Switch to the data state. */
            $this->emitToken($this->token);
            $this->state = 'data';

        } elseif('A' <= $char && $char <= 'Z') {
            // possible optimization: glob further
            /* U+0041 LATIN CAPITAL LETTER A through to U+005A LATIN CAPITAL LETTER Z
            Append the lowercase version of the current input
            character (add 0x0020 to the character's code point) to
            the current tag token's tag name. Stay in the tag name state. */
            $this->token['name'] .= strtolower($char);
            $this->state = 'tagName';

        } elseif($char === false) {
            /* EOF
            Parse error. Emit the current tag token. Reconsume the EOF
            character in the data state. */
            $this->emitToken($this->token);

            $this->EOF();

        } else {
            // possible optimization: glob further
            /* Anything else
            Append the current input character to the current tag token's tag name.
            Stay in the tag name state. */
            $this->token['name'] .= $char;
            $this->state = 'tagName';
        }
    }

    private function beforeAttributeNameState() {
        /* Consume the next input character: */
        $char = $this->c;

        // this conditional is optimized, check bottom
        if(preg_match('/^[\t\n\x0c ]$/', $char)) {
            /* U+0009 CHARACTER TABULATION
            U+000A LINE FEED (LF)
            U+000C FORM FEED (FF)
            U+0020 SPACE
            Stay in the before attribute name state. */
            $this->state = 'beforeAttributeName';

        } elseif($char === '/') {
            /* U+002F SOLIDUS (/)
            Switch to the self-closing start tag state. */
            $this->state = 'selfClosingStartTag';

        } elseif($char === '>') {
            /* U+003E GREATER-THAN SIGN (>)
            Emit the current tag token. Switch to the data state. */
            $this->emitToken($this->token);
            $this->state = 'data';

        } elseif('A' <= $char && $char <= 'Z') {
            /* U+0041 LATIN CAPITAL LETTER A through to U+005A LATIN CAPITAL LETTER Z
            Start a new attribute in the current tag token. Set that
            attribute's name to the lowercase version of the current
            input character (add 0x0020 to the character's code
            point), and its value to the empty string. Switch to the
            attribute name state.*/
            $this->token['attr'][] = array(
                'name'  => strtolower($char),
                'value' => ''
            );

            $this->state = 'attributeName';

        } elseif($char === false) {
            /* EOF
            Parse error. Emit the current tag token. Reconsume the EOF
            character in the data state. */
            $this->emitToken($this->token);

            $this->EOF();

        } else {
            /* U+0022 QUOTATION MARK (")
               U+0027 APOSTROPHE (')
               U+003D EQUALS SIGN (=)
            Parse error. Treat it as per the "anything else" entry
            below. */
            // insert parse error here

            /* Anything else
            Start a new attribute in the current tag token. Set that attribute's
            name to the current input character, and its value to the empty string.
            Switch to the attribute name state. */
            $this->token['attr'][] = array(
                'name'  => $char,
                'value' => ''
            );

            $this->state = 'attributeName';
        }
    }

    private function attributeNameState() {
        // Consume the next input character:
        $char = $this->c;

        // this conditional is optimized, check bottom
        if(preg_match('/^[\t\n\x0c ]$/', $char)) {
            /* U+0009 CHARACTER TABULATION
            U+000A LINE FEED (LF)
            U+000C FORM FEED (FF)
            U+0020 SPACE
            Switch to the after attribute name state. */
            $this->state = 'afterAttributeName';

        } elseif($char === '/' && $this->character($this->char + 1) !== '>') {
            /* U+002F SOLIDUS (/)
            Switch to the self-closing start tag state. */
            $this->state = 'selfClosingStartTag';

        } elseif($char === '=') {
            /* U+003D EQUALS SIGN (=)
            Switch to the before attribute value state. */
            $this->state = 'beforeAttributeValue';

        } elseif($char === '>') {
            /* U+003E GREATER-THAN SIGN (>)
            Emit the current tag token. Switch to the data state. */
            $this->emitToken($this->token);
            $this->state = 'data';

        } elseif('A' <= $char && $char <= 'Z') {
            /* U+0041 LATIN CAPITAL LETTER A through to U+005A LATIN CAPITAL LETTER Z
            Append the lowercase version of the current input
            character (add 0x0020 to the character's code point) to
            the current attribute's name. Stay in the attribute name
            state. */
            $last = count($this->token['attr']) - 1;
            $this->token['attr'][$last]['name'] .= strtolower($char);

            $this->state = 'attributeName';

        } elseif($char === false) {
            /* EOF
            Parse error. Emit the current tag token. Reconsume the EOF
            character in the data state. */
            $this->emitToken($this->token);

            $this->EOF();

        } else {
            /* U+0022 QUOTATION MARK (")
               U+0027 APOSTROPHE (')
            Parse error. Treat it as per the "anything else"
            entry below. */
            
            /* Anything else
            Append the current input character to the current attribute's name.
            Stay in the attribute name state. */
            $last = count($this->token['attr']) - 1;
            $this->token['attr'][$last]['name'] .= $char;

            $this->state = 'attributeName';
        }
        
        /* When the user agent leaves the attribute name state
        (and before emitting the tag token, if appropriate), the
        complete attribute's name must be compared to the other
        attributes on the same token; if there is already an
        attribute on the token with the exact same name, then this
        is a parse error and the new attribute must be dropped, along
        with the value that gets associated with it (if any). */
        // this might be implemented in the emitToken method
    }

    private function afterAttributeNameState() {
        // Consume the next input character:
        $char = $this->c;

        // this is an optimized conditional, check the bottom
        if(preg_match('/^[\t\n\x0c ]$/', $char)) {
            /* U+0009 CHARACTER TABULATION
            U+000A LINE FEED (LF)
            U+000C FORM FEED (FF)
            U+0020 SPACE
            Stay in the after attribute name state. */
            $this->state = 'afterAttributeName';

        } elseif($char === '/' && $this->character($this->char + 1) !== '>') {
            /* U+002F SOLIDUS (/)
            Switch to the self-closing start tag state. */
            $this->state = 'selfClosingStartTag';

        } elseif($char === '=') {
            /* U+003D EQUALS SIGN (=)
            Switch to the before attribute value state. */
            $this->state = 'beforeAttributeValue';

        } elseif($char === '>') {
            /* U+003E GREATER-THAN SIGN (>)
            Emit the current tag token. Switch to the data state. */
            $this->emitToken($this->token);
            $this->state = 'data';

        } elseif('A' <= $char && $char <= 'Z') {
            /* U+0041 LATIN CAPITAL LETTER A through to U+005A LATIN CAPITAL LETTER Z
            Start a new attribute in the current tag token. Set that
            attribute's name to the lowercase version of the current
            input character (add 0x0020 to the character's code
            point), and its value to the empty string. Switch to the
            attribute name state. */
            $this->token['attr'][] = array(
                'name'  => strtolower($char),
                'value' => ''
            );

            $this->state = 'attributeName';

        } elseif($char === false) {
            /* EOF
            Parse error. Emit the current tag token. Reconsume the EOF
            character in the data state. */
            $this->emitToken($this->token);

            $this->EOF();

        } else {
            /* U+0022 QUOTATION MARK (")
               U+0027 APOSTROPHE (')
            Parse error. Treat it as per the "anything else"
            entry below. */
            
            /* Anything else
            Start a new attribute in the current tag token. Set that attribute's
            name to the current input character, and its value to the empty string.
            Switch to the attribute name state. */
            $this->token['attr'][] = array(
                'name'  => $char,
                'value' => ''
            );

            $this->state = 'attributeName';
        }
    }

    private function beforeAttributeValueState() {
        // Consume the next input character:
        $char = $this->c;

        // this is an optimized conditional
        if(preg_match('/^[\t\n\x0c ]$/', $char)) {
            /* U+0009 CHARACTER TABULATION
            U+000A LINE FEED (LF)
            U+000C FORM FEED (FF)
            U+0020 SPACE
            Stay in the before attribute value state. */
            $this->state = 'beforeAttributeValue';

        } elseif($char === '"') {
            /* U+0022 QUOTATION MARK (")
            Switch to the attribute value (double-quoted) state. */
            $this->state = 'attributeValueDoubleQuoted';

        } elseif($char === '&') {
            /* U+0026 AMPERSAND (&)
            Switch to the attribute value (unquoted) state and reconsume
            this input character. */
            $this->state = 'attributeValueUnquoted';
            $this->attributeValueUnquotedState();

        } elseif($char === '\'') {
            /* U+0027 APOSTROPHE (')
            Switch to the attribute value (single-quoted) state. */
            $this->state = 'attributeValueSingleQuoted';

        } elseif($char === '>') {
            /* U+003E GREATER-THAN SIGN (>)
            Parse error. Emit the current tag token. Switch to the data state. */
            $this->emitToken($this->token);
            $this->state = 'data';

        } elseif($char === false) {
            /* EOF
            Parse error. Emit the current tag token. Reconsume
            the character in the data state. */
            $this->emitToken($this->token);
            $this->EOF();

        } else {
            /* U+003D EQUALS SIGN (=)
            Parse error. Treat it as per the "anything else" entry below. */

            /* Anything else
            Append the current input character to the current attribute's value.
            Switch to the attribute value (unquoted) state. */
            $last = count($this->token['attr']) - 1;
            $this->token['attr'][$last]['value'] .= $char;

            $this->state = 'attributeValueUnquoted';
        }
    }

    private function attributeValueDoubleQuotedState() {
        // Consume the next input character:
        $char = $this->c;

        if($char === '"') {
            /* U+0022 QUOTATION MARK (")
            Switch to the after attribute value (quoted) state. */
            $this->state = 'afterAttributeValueQuoted';

        } elseif($char === '&') {
            /* U+0026 AMPERSAND (&)
            Switch to the character reference in attribute value
            state, with the additional allowed character 
            being U+0022 QUOTATION MARK ("). */
            $this->characterReferenceInAttributeValue('"');

        } elseif($char === false) {
            /* EOF
            Parse error. Emit the current tag token. Reconsume the character
            in the data state. */
            $this->emitToken($this->token);

            $this->state = 'data';
            $this->dataState();

        } else {
            /* Anything else
            Append the current input character to the current attribute's value.
            Stay in the attribute value (double-quoted) state. */
            $last = count($this->token['attr']) - 1;
            $this->token['attr'][$last]['value'] .= $char;

            $this->state = 'attributeValueDoubleQuoted';
        }
    }

    private function attributeValueSingleQuotedState() {
        // Consume the next input character:
        $char = $this->c;

        if($char === "'") {
            /* U+0022 QUOTATION MARK (')
            Switch to the after attribute value state. */
            $this->state = 'afterAttributeValueQuoted';

        } elseif($char === '&') {
            /* U+0026 AMPERSAND (&)
            Switch to the entity in attribute value state. */
            $this->characterReferenceInAttributeValue("'");

        } elseif($char === false) {
            /* EOF
            Parse error. Emit the current tag token. Reconsume the character
            in the data state. */
            $this->emitToken($this->token);

            $this->EOF();

        } else {
            /* Anything else
            Append the current input character to the current attribute's value.
            Stay in the attribute value (single-quoted) state. */
            $last = count($this->token['attr']) - 1;
            $this->token['attr'][$last]['value'] .= $char;

            $this->state = 'attributeValueSingleQuoted';
        }
    }

    private function attributeValueUnquotedState() {
        // Consume the next input character:
        $char = $this->c;

        if(preg_match('/^[\t\n\x0c ]$/', $char)) {
            /* U+0009 CHARACTER TABULATION
            U+000A LINE FEED (LF)
            U+000C FORM FEED (FF)
            U+0020 SPACE
            Switch to the before attribute name state. */
            $this->state = 'beforeAttributeName';

        } elseif($char === '&') {
            /* U+0026 AMPERSAND (&)
            Switch to the entity in attribute value state. */
            $this->characterReferenceInAttributeValue();

        } elseif($char === '>') {
            /* U+003E GREATER-THAN SIGN (>)
            Emit the current tag token. Switch to the data state. */
            $this->emitToken($this->token);
            $this->state = 'data';

        } elseif ($this->char == $this->EOF) {
            /* EOF
            Parse error. Emit the current tag token. Reconsume
            the character in the data state. */
            $this->emitToken($this->token);
            $this->EOF();

        } else {
            /* U+0022 QUOTATION MARK (")
               U+0027 APOSTROPHE (')
               U+003D EQUALS SIGN (=)
            Parse error. Treat it as per the "anything else"
            entry below. */
            
            /* Anything else
            Append the current input character to the current attribute's value.
            Stay in the attribute value (unquoted) state. */
            $last = count($this->token['attr']) - 1;
            $this->token['attr'][$last]['value'] .= $char;

            $this->state = 'attributeValueUnquoted';
        }
    }

    // this state is not actually called using the state machine
    // but invoked directly
    private function characterReferenceInAttributeValue($allowed = false) {
        /* Attempt to consume a character reference. */
        $entity = $this->consumeCharacterReference($allowed, true);

        /* If nothing is returned, append a U+0026 AMPERSAND
        character to the current attribute's value.

        Otherwise, append the returned character token to the
        current attribute's value. */
        $char = (!$entity)
            ? '&'
            : $entity;

        $last = count($this->token['attr']) - 1;
        $this->token['attr'][$last]['value'] .= $char;

        // see method name for impl
        /* Finally, switch back to the attribute value state that you
        were in when were switched into this state. */
    }

    private function afterAttributeValueQuotedState() {
        /* Consume the next input character: */
        $char = $this->c;

        if(preg_match('/^[\t\n\x0c ]$/', $char)) {
            /* U+0009 CHARACTER TABULATION
               U+000A LINE FEED (LF)
               U+000C FORM FEED (FF)
               U+0020 SPACE
            Switch to the before attribute name state. */
            $this->state = 'beforeAttributeName';

        } elseif ($char == '/') {
            /* U+002F SOLIDUS (/)
            Switch to the self-closing start tag state. */
            $this->state = 'selfClosingStartTag';

        } elseif ($char == '>') {
            /* U+003E GREATER-THAN SIGN (>)
            Emit the current tag token. Switch to the data state. */
            $this->emitToken($this->token);
            $this->state = 'data';

        } elseif ($this->char === self::EOF) {
            /* EOF
            Parse error. Emit the current tag token. Reconsume the EOF
            character in the data state. */
            $this->emitToken($this->token);
            $this->EOF();

        } else {
            /* Anything else
            Parse error. Reconsume the character in the before attribute
            name state. */
            $this->state = 'beforeAttributeName';
            $this->beforeAttributeNameState();
        }
    }

    private function selfClosingStartTagState() {
        /* Consume the next input character: */
        $char = $this->c;

        if ($char === '>') {
            /* U+003E GREATER-THAN SIGN (>)
            Set the self-closing flag of the current tag token.
            Emit the current tag token. Switch to the data state. */
            // not sure if this is the name we want
            $this->token['self-closing'] = true;
            $this->emitToken($this->token);
            $this->state = 'data';

        } elseif ($this->char === self::EOF) {
            /* EOF
            Parse error. Emit the current tag token. Reconsume the
            EOF character in the data state. */
            $this->emitToken($this->token);
            $this->EOF();

        } else {
            /* Anything else
            Parse error. Reconsume the character in the before attribute name state. */
            $this->state = 'beforeAttributeName';
            $this->beforeAttributeNameState();
        }
    }

    private function bogusCommentState() {
        /* (This can only happen if the content model flag is set to the PCDATA state.) */
        /* Consume every character up to the first U+003E GREATER-THAN SIGN
        character (>) or the end of the file (EOF), whichever comes first. Emit
        a comment token whose data is the concatenation of all the characters
        starting from and including the character that caused the state machine
        to switch into the bogus comment state, up to and including the last
        consumed character before the U+003E character, if any, or up to the
        end of the file otherwise. (If the comment was started by the end of
        the file (EOF), the token is empty.) */
        
        $len = strcspn($this->data, '>', $this->char);
        $data = (string) substr($this->data, $this->char, $len);
        $this->char += $len;
        
        $this->emitToken(array(
            'data' => $data,
            'type' => self::COMMENT
        ));

        /* Switch to the data state. */
        $this->state = 'data';

        /* If the end of the file was reached, reconsume the EOF character. */
        if($this->char === $this->EOF) {
            $this->EOF();
        }
    }

    private function markupDeclarationOpenState() {
        
        /* If the next two characters are both U+002D HYPHEN-MINUS (-)
        characters, consume those two characters, create a comment token whose
        data is the empty string, and switch to the comment state. */
        if($this->character($this->char, 2) === '--') {
            $this->char++;
            $this->state = 'commentStart';
            $this->token = array(
                'data' => '',
                'type' => self::COMMENT
            );

        /* Otherwise if the next seven characters are a case-insensitive match
        for the word "DOCTYPE", then consume those characters and switch to the
        DOCTYPE state. */
        } elseif(strtoupper($this->character($this->char, 7)) === 'DOCTYPE') {
            $this->char += 6;
            $this->state = 'doctype';

        // XXX not implemented
        /* Otherwise, if the insertion mode is "in foreign content"
        and the current node is not an element in the HTML namespace
        and the next seven characters are an ASCII case-sensitive
        match for the string "[CDATA[" (the five uppercase letters
        "CDATA" with a U+005B LEFT SQUARE BRACKET character before
        and after), then consume those characters and switch to the
        CDATA section state (which is unrelated to the content model
        flag's CDATA state). */
        
        /* Otherwise, is is a parse error. Switch to the bogus comment state.
        The next character that is consumed, if any, is the first character
        that will be in the comment. */
        } else {
            $this->state = 'bogusComment';
        }
    }

    private function commentStartState() {
        /* Consume the next input character: */
        $char = $this->c;
        
        if ($char === '-') {
            /* U+002D HYPHEN-MINUS (-)
            Switch to the comment start dash state. */
            $this->state = 'commentStartDash';
        } elseif ($char === '>') {
            /* U+003E GREATER-THAN SIGN (>)
            Parse error. Emit the comment token. Switch to the
            data state. */
            $this->emitToken($this->token);
            $this->state = 'data';
        } elseif ($char === false) {
            /* EOF
            Parse error. Emit the comment token. Reconsume the
            EOF character in the data state. */
            $this->emitToken($this->token);
            $this->EOF();
        } else {
            /* Anything else
            Append the input character to the comment token's
            data. Switch to the comment state. */
            $this->token['data'] .= $char;
            $this->state = 'comment';
        }
    }
    
    private function commentStartDashState() {
        /* Consume the next input character: */
        $char = $this->c;
        if ($char === '-') {
            /* U+002D HYPHEN-MINUS (-)
            Switch to the comment end state */
            $this->state = 'commentEnd';
        } elseif ($char === '>') {
            /* U+003E GREATER-THAN SIGN (>)
            Parse error. Emit the comment token. Switch to the
            data state. */
            $this->emitToken($this->token);
            $this->state = 'data';
        } elseif ($char === false) {
            /* Parse error. Emit the comment token. Reconsume the
            EOF character in the data state. */
            $this->emitToken($this->token);
            $this->EOF();
        } else {
            $this->token['data'] .= '-' . $char;
            $this->state = 'comment';
        }
    }

    private function commentState() {
        /* Consume the next input character: */
        $char = $this->c;

        if($char === '-') {
            /* U+002D HYPHEN-MINUS (-)
            Switch to the comment end dash state */
            $this->state = 'commentEndDash';

        } elseif($char === false) {
            /* EOF 
            Parse error. Emit the comment token. Reconsume the EOF character
            in the data state. */
            $this->emitToken($this->token);
            $this->EOF();

        } else {
            /* Anything else
            Append the input character to the comment token's data. Stay in
            the comment state. */
            $this->token['data'] .= $char;
        }
    }

    private function commentEndDashState() {
        /* Consume the next input character: */
        $char = $this->c;

        if($char === '-') {
            /* U+002D HYPHEN-MINUS (-)
            Switch to the comment end state  */
            $this->state = 'commentEnd';

        } elseif($char === false) {
            /* EOF
            Parse error. Emit the comment token. Reconsume the EOF character
            in the data state. */
            $this->emitToken($this->token);
            $this->EOF();

        } else {
            /* Anything else
            Append a U+002D HYPHEN-MINUS (-) character and the input
            character to the comment token's data. Switch to the comment state. */
            $this->token['data'] .= '-'.$char;
            $this->state = 'comment';
        }
    }

    private function commentEndState() {
        /* Consume the next input character: */
        $char = $this->c;

        if($char === '>') {
            /* U+003E GREATER-THAN SIGN (>)
            Emit the comment token. Switch to the data state. */
            $this->emitToken($this->token);
            $this->state = 'data';

        } elseif($char === '-') {
            /* U+002D HYPHEN-MINUS (-)
            Parse error. Append a U+002D HYPHEN-MINUS (-) character
            to the comment token's data. Stay in the comment end
            state. */
            $this->token['data'] .= '-';

        } elseif($char === false) {
            /* EOF
            Parse error. Emit the comment token. Reconsume the
            EOF character in the data state. */
            $this->emitToken($this->token);
            $this->EOF();

        } else {
            /* Anything else
            Parse error. Append two U+002D HYPHEN-MINUS (-)
            characters and the input character to the comment token's
            data. Switch to the comment state. */
            $this->token['data'] .= '--'.$char;
            $this->state = 'comment';
        }
    }

    private function doctypeState() {
        /* Consume the next input character: */
        $char = $this->c;

        if(preg_match('/^[\t\n\x0c ]$/', $char)) {
            /* U+0009 CHARACTER TABULATION
               U+000A LINE FEED (LF)
               U+000C FORM FEED (FF)
               U+0020 SPACE
            Switch to the before DOCTYPE name state. */
            $this->state = 'beforeDoctypeName';

        } else {
            /* Anything else
            Parse error. Reconsume the current character in the
            before DOCTYPE name state. */
            $this->state = 'beforeDoctypeName';
            $this->beforeDoctypeNameState();
        }
    }

    private function beforeDoctypeNameState() {
        /* Consume the next input character: */
        $char = $this->c;

        if(preg_match('/^[\t\n\x0a\x0c ]$/', $char)) {
            /* U+0009 CHARACTER TABULATION
               U+000A LINE FEED (LF)
               U+000C FORM FEED (FF)
               U+0020 SPACE
            Stay in the before DOCTYPE name state. */

        } elseif($char === '>') {
            /* U+003E GREATER-THAN SIGN (>)
            Parse error. Create a new DOCTYPE token. Set its
            force-quirks flag to on. Emit the token. Switch to the
            data state. */
            $this->emitToken(array(
                'name' => '',
                'type' => self::DOCTYPE,
                'force-quirks' => true,
                'error' => true
            ));

            $this->state = 'data';

        } elseif('A' <= $char && $char <= 'Z') {
            /* U+0041 LATIN CAPITAL LETTER A through to U+005A LATIN CAPITAL LETTER Z
            Create a new DOCTYPE token. Set the token's name to the
            lowercase version of the input character (add 0x0020 to
            the character's code point). Switch to the DOCTYPE name
            state. */
            $this->token = array(
                'name' => strtolower($char),
                'type' => self::DOCTYPE,
                'error' => true
            );

            $this->state = 'doctypeName';

        } elseif($char === false) {
            /* EOF
            Parse error. Create a new DOCTYPE token. Set its
            force-quirks flag to on. Emit the token. Reconsume the
            EOF character in the data state. */
            $this->emitToken(array(
                'name' => '',
                'type' => self::DOCTYPE,
                'force-quirks' => true,
                'error' => true
            ));

            $this->EOF();

        } else {
            /* Anything else
            Create a new DOCTYPE token. Set the token's name to the
            current input character. Switch to the DOCTYPE name state. */
            $this->token = array(
                'name' => $char,
                'type' => self::DOCTYPE,
                'error' => true
            );

            $this->state = 'doctypeName';
        }
    }

    private function doctypeNameState() {
        /* Consume the next input character: */
        $char = $this->c;

        if(preg_match('/^[\t\n\x0c ]$/', $char)) {
            /* U+0009 CHARACTER TABULATION
               U+000A LINE FEED (LF)
               U+000C FORM FEED (FF)
               U+0020 SPACE
            Switch to the after DOCTYPE name state. */
            $this->state = 'afterDoctypeName';

        } elseif($char === '>') {
            /* U+003E GREATER-THAN SIGN (>)
            Emit the current DOCTYPE token. Switch to the data state. */
            $this->emitToken($this->token);
            $this->state = 'data';

        } elseif('A' <= $char && $char <= 'Z') {
            /* U+0041 LATIN CAPITAL LETTER A through to U+005A LATIN CAPITAL LETTER Z
            Append the lowercase version of the input character
            (add 0x0020 to the character's code point) to the current
            DOCTYPE token's name. Stay in the DOCTYPE name state. */
            $this->token['name'] .= strtolower($char);

        } elseif($char === false) {
            /* EOF
            Parse error. Set the DOCTYPE token's force-quirks flag
            to on. Emit that DOCTYPE token. Reconsume the EOF
            character in the data state. */
            $this->token['force-quirks'] = true;
            $this->emitToken($this->token);
            $this->EOF();

        } else {
            /* Anything else
            Append the current input character to the current
            DOCTYPE token's name. Stay in the DOCTYPE name state. */
            $this->token['name'] .= $char;
        }

        // XXX this is probably some sort of quirks mode designation,
        // check tree-builder to be sure. In general 'error' needs
        // to be specc'ified, this probably means removing it at the end
        $this->token['error'] = ($this->token['name'] === 'HTML')
            ? false
            : true;
    }

    private function afterDoctypeNameState() {
        /* Consume the next input character: */
        $char = $this->c;

        if(preg_match('/^[\t\n\x0c ]$/', $char)) {
            /* U+0009 CHARACTER TABULATION
               U+000A LINE FEED (LF)
               U+000C FORM FEED (FF)
               U+0020 SPACE
            Stay in the after DOCTYPE name state. */

        } elseif($char === '>') {
            /* U+003E GREATER-THAN SIGN (>)
            Emit the current DOCTYPE token. Switch to the data state. */
            $this->emitToken($this->token);
            $this->state = 'data';

        } elseif($char === false) {
            /* EOF
            Parse error. Set the DOCTYPE token's force-quirks flag
            to on. Emit that DOCTYPE token. Reconsume the EOF
            character in the data state. */
            $this->token['force-quirks'] = true;
            $this->emitToken($this->token);
            $this->EOF();

        } else {
            /* Anything else */

            $nextSix = strtoupper($this->character($this->char, 6));
            if ($nextSix === 'PUBLIC') {
                /* If the next six characters are an ASCII
                case-insensitive match for the word "PUBLIC", then
                consume those characters and switch to the before
                DOCTYPE public identifier state. */
                // remember, we've already consumed the first P of
                // the nextSix. I believe this is an error in the spec.
                $this->char += 5;
                $this->state = 'beforeDoctypePublicIdentifier';

            } elseif ($nextSix === 'SYSTEM') {
                /* Otherwise, if the next six characters are an ASCII
                case-insensitive match for the word "SYSTEM", then
                consume those characters and switch to the before
                DOCTYPE system identifier state. */
                $this->char += 5;
                $this->state = 'beforeDoctypeSystemIdentifier';

            } else {
                /* Otherwise, this is the parse error. Set the DOCTYPE
                token's force-quirks flag to on. Switch to the bogus
                DOCTYPE state. */
                $this->token['force-quirks'] = true;
                $this->token['error'] = true;
                $this->state = 'bogusDoctype';
            }
        }
    }
    
    private function beforeDoctypePublicIdentifierState() {
        /* Consume the next input character: */
        $char = $this->c;
        
        if (preg_match('/^[\t\n\x0c ]$/', $char)) {
            /* U+0009 CHARACTER TABULATION
               U+000A LINE FEED (LF)
               U+000C FORM FEED (FF)
               U+0020 SPACE
            Stay in the before DOCTYPE public identifier state. */
        } elseif ($char === '"') {
            /* U+0022 QUOTATION MARK (")
            Set the DOCTYPE token's public identifier to the empty
            string (not missing), then switch to the DOCTYPE public
            identifier (double-quoted) state. */
            $this->token['public'] = '';
            $this->state = 'doctypePublicIdentifierDoubleQuoted';
        } elseif ($char === "'") {
            /* U+0027 APOSTROPHE (')
            Set the DOCTYPE token's public identifier to the empty
            string (not missing), then switch to the DOCTYPE public
            identifier (single-quoted) state. */
            $this->token['public'] = '';
            $this->state = 'doctypePublicIdentifierSingleQuoted';
        } elseif ($char === '>') {
            /* Parse error. Set the DOCTYPE token's force-quirks flag
            to on. Emit that DOCTYPE token. Switch to the data state. */
            $this->token['force-quirks'] = true;
            $this->emitToken($this->token);
            $this->state = 'data';
        } elseif ($char === false) {
            /* Parse error. Set the DOCTYPE token's force-quirks
            flag to on. Emit that DOCTYPE token. Reconsume the EOF
            character in the data state. */
            $this->token['force-quirks'] = true;
            $this->emitToken($this->token);
            $this->EOF();
        } else {
            /* Parse error. Set the DOCTYPE token's force-quirks flag
            to on. Switch to the bogus DOCTYPE state. */
            $this->token['force-quirks'] = true;
            $this->state = 'bogusDoctype';
        }
    }
    
    private function doctypePublicIdentifierDoubleQuotedState() {
        /* Consume the next input character: */
        $char = $this->c;
        
        if ($char === '"') {
            /* U+0022 QUOTATION MARK (")
            Switch to the after DOCTYPE public identifier state. */
            $this->state = 'afterDoctypePublicIdentifier';
        } elseif ($char === '>') {
            /* U+003E GREATER-THAN SIGN (>)
            Parse error. Set the DOCTYPE token's force-quirks flag
            to on. Emit that DOCTYPE token. Switch to the data state. */
            $this->token['force-quirks'] = true;
            $this->emitToken($this->token);
            $this->state = 'data';
        } elseif ($char === false) {
            /* EOF
            Parse error. Set the DOCTYPE token's force-quirks flag
            to on. Emit that DOCTYPE token. Reconsume the EOF
            character in the data state. */
            $this->token['force-quirks'] = true;
            $this->emitToken($this->token);
            $this->EOF();
        } else {
            /* Anything else
            Append the current input character to the current
            DOCTYPE token's public identifier. Stay in the DOCTYPE
            public identifier (double-quoted) state. */
            $this->token['public'] .= $char;
        }
    }
    
    private function doctypePublicIdentifierSingleQuotedState() {
        /* Consume the next input character: */
        $char = $this->c;
        
        if ($char === "'") {
            /* U+0027 APOSTROPHE (')
            Switch to the after DOCTYPE public identifier state. */
            $this->state = 'afterDoctypePublicIdentifier';
        } elseif ($char === '>') {
            /* U+003E GREATER-THAN SIGN (>)
            Parse error. Set the DOCTYPE token's force-quirks flag
            to on. Emit that DOCTYPE token. Switch to the data state. */
            $this->token['force-quirks'] = true;
            $this->emitToken($this->token);
            $this->state = 'data';
        } elseif ($char === false) {
            /* EOF
            Parse error. Set the DOCTYPE token's force-quirks flag
            to on. Emit that DOCTYPE token. Reconsume the EOF
            character in the data state. */
            $this->token['force-quirks'] = true;
            $this->emitToken($this->token);
            $this->EOF();
        } else {
            /* Anything else
            Append the current input character to the current
            DOCTYPE token's public identifier. Stay in the DOCTYPE
            public identifier (double-quoted) state. */
            $this->token['public'] .= $char;
        }
    }
    
    private function afterDoctypePublicIdentifierState() {
        /* Consume the next input character: */
        $char = $this->c;
        
        if (preg_match('/^[\t\n\x0c ]$/', $char)) {
            /* U+0009 CHARACTER TABULATION
               U+000A LINE FEED (LF)
               U+000C FORM FEED (FF)
               U+0020 SPACE
            Stay in the after DOCTYPE public identifier state. */
        } elseif ($char === '"') {
            /* U+0022 QUOTATION MARK (")
            Set the DOCTYPE token's system identifier to the
            empty string (not missing), then switch to the DOCTYPE
            system identifier (double-quoted) state. */
            $this->token['system'] = '';
            $this->state = 'doctypeSystemIdentifierDoubleQuoted';
        } elseif ($char === "'") {
            /* U+0027 APOSTROPHE (')
            Set the DOCTYPE token's system identifier to the
            empty string (not missing), then switch to the DOCTYPE
            system identifier (single-quoted) state. */
            $this->token['system'] = '';
            $this->state = 'doctypeSystemIdentifierSingleQuoted';
        } elseif ($char === '>') {
            /* U+003E GREATER-THAN SIGN (>)
            Emit the current DOCTYPE token. Switch to the data state. */
            $this->emitToken($this->token);
            $this->state = 'data';
        } elseif ($char === false) {
            /* Parse error. Set the DOCTYPE token's force-quirks
            flag to on. Emit that DOCTYPE token. Reconsume the EOF
            character in the data state. */
            $this->token['force-quirks'] = true;
            $this->emitToken($this->token);
            $this->EOF();
        } else {
            /* Anything else
            Parse error. Set the DOCTYPE token's force-quirks flag 
            to on. Switch to the bogus DOCTYPE state. */
            $this->token['force-quirks'] = true;
            $this->state = 'bogusDoctype';
        }
    }
    
    private function beforeDoctypeSystemIdentifierState() {
        /* Consume the next input character: */
        $char = $this->c;
        
        if (preg_match('/^[\t\n\x0c ]$/', $char)) {
            /* U+0009 CHARACTER TABULATION
               U+000A LINE FEED (LF)
               U+000C FORM FEED (FF)
               U+0020 SPACE
            Stay in the before DOCTYPE system identifier state. */
        } elseif ($char === '"') {
            /* U+0022 QUOTATION MARK (")
            Set the DOCTYPE token's system identifier to the empty
            string (not missing), then switch to the DOCTYPE system
            identifier (double-quoted) state. */
            $this->token['system'] = '';
            $this->state = 'doctypeSystemIdentifierDoubleQuoted';
        } elseif ($char === "'") {
            /* U+0027 APOSTROPHE (')
            Set the DOCTYPE token's system identifier to the empty
            string (not missing), then switch to the DOCTYPE system
            identifier (single-quoted) state. */
            $this->token['system'] = '';
            $this->state = 'doctypeSystemIdentifierSingleQuoted';
        } elseif ($char === '>') {
            /* Parse error. Set the DOCTYPE token's force-quirks flag
            to on. Emit that DOCTYPE token. Switch to the data state. */
            $this->token['force-quirks'] = true;
            $this->emitToken($this->token);
            $this->state = 'data';
        } elseif ($char === false) {
            /* Parse error. Set the DOCTYPE token's force-quirks
            flag to on. Emit that DOCTYPE token. Reconsume the EOF
            character in the data state. */
            $this->token['force-quirks'] = true;
            $this->emitToken($this->token);
            $this->EOF();
        } else {
            /* Parse error. Set the DOCTYPE token's force-quirks flag
            to on. Switch to the bogus DOCTYPE state. */
            $this->token['force-quirks'] = true;
            $this->state = 'bogusDoctype';
        }
    }
    
    private function doctypeSystemIdentifierDoubleQuotedState() {
        /* Consume the next input character: */
        $char = $this->c;
        
        if ($char === '"') {
            /* U+0022 QUOTATION MARK (")
            Switch to the after DOCTYPE system identifier state. */
            $this->state = 'afterDoctypeSystemIdentifier';
        } elseif ($char === '>') {
            /* U+003E GREATER-THAN SIGN (>)
            Parse error. Set the DOCTYPE token's force-quirks flag
            to on. Emit that DOCTYPE token. Switch to the data state. */
            $this->token['force-quirks'] = true;
            $this->emitToken($this->token);
            $this->state = 'data';
        } elseif ($char === false) {
            /* EOF
            Parse error. Set the DOCTYPE token's force-quirks flag
            to on. Emit that DOCTYPE token. Reconsume the EOF
            character in the data state. */
            $this->token['force-quirks'] = true;
            $this->emitToken($this->token);
            $this->EOF();
        } else {
            /* Anything else
            Append the current input character to the current
            DOCTYPE token's system identifier. Stay in the DOCTYPE
            system identifier (double-quoted) state. */
            $this->token['system'] .= $char;
        }
    }
    
    private function doctypeSystemIdentifierSingleQuotedState() {
        /* Consume the next input character: */
        $char = $this->c;
        
        if ($char === "'") {
            /* U+0027 APOSTROPHE (')
            Switch to the after DOCTYPE system identifier state. */
            $this->state = 'afterDoctypeSystemIdentifier';
        } elseif ($char === '>') {
            /* U+003E GREATER-THAN SIGN (>)
            Parse error. Set the DOCTYPE token's force-quirks flag
            to on. Emit that DOCTYPE token. Switch to the data state. */
            $this->token['force-quirks'] = true;
            $this->emitToken($this->token);
            $this->state = 'data';
        } elseif ($char === false) {
            /* EOF
            Parse error. Set the DOCTYPE token's force-quirks flag
            to on. Emit that DOCTYPE token. Reconsume the EOF
            character in the data state. */
            $this->token['force-quirks'] = true;
            $this->emitToken($this->token);
            $this->EOF();
        } else {
            /* Anything else
            Append the current input character to the current
            DOCTYPE token's system identifier. Stay in the DOCTYPE
            system identifier (double-quoted) state. */
            $this->token['system'] .= $char;
        }
    }
    
    private function afterDoctypeSystemIdentifierState() {
        /* Consume the next input character: */
        $char = $this->c;
        
        if (preg_match('/^[\t\n\x0c ]$/', $char)) {
            /* U+0009 CHARACTER TABULATION
               U+000A LINE FEED (LF)
               U+000C FORM FEED (FF)
               U+0020 SPACE
            Stay in the after DOCTYPE system identifier state. */
        } elseif ($char === '>') {
            /* U+003E GREATER-THAN SIGN (>)
            Emit the current DOCTYPE token. Switch to the data state. */
            $this->emitToken($this->token);
            $this->state = 'data';
        } elseif ($char === false) {
            /* Parse error. Set the DOCTYPE token's force-quirks
            flag to on. Emit that DOCTYPE token. Reconsume the EOF
            character in the data state. */
            $this->token['force-quirks'] = true;
            $this->emitToken($this->token);
            $this->EOF();
        } else {
            /* Anything else
            Parse error. Switch to the bogus DOCTYPE state.
            (This does not set the DOCTYPE token's force-quirks
            flag to on.) */
            $this->state = 'bogusDoctype';
        }
    }
    
    private function bogusDoctypeState() {
        /* Consume the next input character: */
        $char = $this->c;

        if ($char === '>') {
            /* U+003E GREATER-THAN SIGN (>)
            Emit the DOCTYPE token. Switch to the data state. */
            $this->emitToken($this->token);
            $this->state = 'data';

        } elseif($char === false) {
            /* EOF
            Emit the DOCTYPE token. Reconsume the EOF character in
            the data state. */
            $this->emitToken($this->token);
            $this->EOF();

        } else {
            /* Anything else
            Stay in the bogus DOCTYPE state. */
        }
    }

    // private function cdataSectionState() {}
    
    private function consumeCharacterReference($allowed = false, $inattr = false) {
        /* This section defines how to consume a character
        reference. This definition is used when parsing character
        references in text and in attributes.

        The behavior depends on the identity of the next character
        (the one immediately after the U+0026 AMPERSAND character): */

        
        $next = $this->character($this->char + 1);
        if (
            $next === null ||
            // possible optimization
            preg_match("/^[\x09\n\x0c <&]$/", $next) ||
            ($allowed !== false && $next === $allowed)
        ) {
            /* U+0009 CHARACTER TABULATION
               U+000A LINE FEED (LF)
               U+000C FORM FEED (FF)
               U+0020 SPACE
               U+003C LESS-THAN SIGN
               U+0026 AMPERSAND
               EOF
               The additional allowed character, if there is one
            Not a character reference. No characters are consumed,
            and nothing is returned. (This is not an error, either.) */
            return false;
        } elseif ($next == '#') {
            /* Consume the U+0023 NUMBER SIGN. */
            $start = $this->char;
            $this->char++;
            /* The behavior further depends on the character after
            the U+0023 NUMBER SIGN: */
            switch($this->character($this->char + 1)) {
                /* U+0078 LATIN SMALL LETTER X
                   U+0058 LATIN CAPITAL LETTER X */
                case 'x':
                case 'X':
                    /* Consume the X. */
                    $this->char++;
                    /* Follow the steps below, but using the range of
                    characters U+0030 DIGIT ZERO through to U+0039 DIGIT
                    NINE, U+0061 LATIN SMALL LETTER A through to U+0066
                    LATIN SMALL LETTER F, and U+0041 LATIN CAPITAL LETTER
                    A, through to U+0046 LATIN CAPITAL LETTER F (in other
                    words, 0-9, A-F, a-f). */
                    $char_class = '0-9A-Fa-f';
                    /* When it comes to interpreting the
                    number, interpret it as a hexadecimal number. */
                    $hex = true;
                break;

                /* Anything else */
                default:
                    /* Follow the steps below, but using the range of
                    characters U+0030 DIGIT ZERO through to U+0039 DIGIT
                    NINE (i.e. just 0-9). */
                    $char_class = '0-9';
                    /* When it comes to interpreting the number,
                    interpret it as a decimal number. */
                    $hex = false;
                break;
            }

            /* Consume as many characters as match the range of characters given above. */
            $consumed = $this->characters($char_class, $this->char + 1);
            if ($consumed !== '') {
                $len = strlen($consumed);
                $this->char += $len;
            } else {
                /* If no characters match the range, then don't consume
                any characters (and unconsume the U+0023 NUMBER SIGN
                character and, if appropriate, the X character). This
                is a parse error; nothing is returned. */
                $this->char = $start;
                return false;
            }
            
            /* Otherwise, if the next character is a U+003B SEMICOLON,
            consume that too. If it isn't, there is a parse error. */
            if ($this->character($this->char + 1) === ';') {
                $this->char++;
            } else {
                // parse error
            }
            
            /* If one or more characters match the range, then take
            them all and interpret the string of characters as a number
            (either hexadecimal or decimal as appropriate). */
            $codepoint = $hex ? hexdec($consumed) : (int) $consumed;

            /* If that number is one of the numbers in the first column
            of the following table, then this is a parse error. Find the
            row with that number in the first column, and return a
            character token for the Unicode character given in the
            second column of that row. */
            $new_codepoint = HTML5_Data::getRealCodepoint($codepoint);
            if ($new_codepoint) {
                // parse error
                $codepoint = $new_codepoint;
            }

            /* Otherwise, if the number is in the range 0x0000 to 0x0008,
            U+000B,  U+000E to 0x001F,  0x007F  to 0x009F, 0xD800 to 0xDFFF ,
            0xFDD0 to 0xFDEF, or is one of 0xFFFE, 0xFFFF, 0x1FFFE, 0x1FFFF,
            0x2FFFE, 0x2FFFF, 0x3FFFE, 0x3FFFF, 0x4FFFE, 0x4FFFF, 0x5FFFE,
            0x5FFFF, 0x6FFFE, 0x6FFFF, 0x7FFFE, 0x7FFFF, 0x8FFFE, 0x8FFFF,
            0x9FFFE, 0x9FFFF, 0xAFFFE, 0xAFFFF, 0xBFFFE, 0xBFFFF, 0xCFFFE,
            0xCFFFF, 0xDFFFE, 0xDFFFF, 0xEFFFE, 0xEFFFF, 0xFFFFE, 0xFFFFF,
            0x10FFFE, or 0x10FFFF, or is higher than 0x10FFFF, then this
            is a parse error; return a character token for the U+FFFD
            REPLACEMENT CHARACTER character instead. */
            // && has higher precedence than ||
            if (
                $codepoint >= 0x0000 && $codepoint <= 0x0008 ||
                $codepoint >= 0x000E && $codepoint <= 0x001F ||
                $codepoint >= 0x007F && $codepoint <= 0x009F ||
                $codepoint >= 0xD800 && $codepoint <= 0xDFFF ||
                $codepoint >= 0xFDD0 && $codepoint <= 0xFDEF ||
                $codepoint == 0x000B ||
                $codepoint == 0xFFFE || $codepoint == 0xFFFF ||
                $codepoint == 0x1FFFE || $codepoint == 0x1FFFF ||
                $codepoint == 0x2FFFE || $codepoint == 0x2FFFF ||
                $codepoint == 0x3FFFE || $codepoint == 0x3FFFF ||
                $codepoint == 0x4FFFE || $codepoint == 0x4FFFF ||
                $codepoint == 0x5FFFE || $codepoint == 0x5FFFF ||
                $codepoint == 0x6FFFE || $codepoint == 0x6FFFF ||
                $codepoint == 0x7FFFE || $codepoint == 0x7FFFF ||
                $codepoint == 0x8FFFE || $codepoint == 0x8FFFF ||
                $codepoint == 0x9FFFE || $codepoint == 0x9FFFF ||
                $codepoint == 0xAFFFE || $codepoint == 0xAFFFF ||
                $codepoint == 0xBFFFE || $codepoint == 0xBFFFF ||
                $codepoint == 0xCFFFE || $codepoint == 0xCFFFF ||
                $codepoint == 0xDFFFE || $codepoint == 0xDFFFF ||
                $codepoint == 0xEFFFE || $codepoint == 0xEFFFF ||
                $codepoint == 0xFFFFE || $codepoint == 0xFFFFF ||
                $codepoint == 0x10FFFE || $codepoint == 0x10FFFF ||
                $codepoint > 0x10FFFF
            ) {
                // parse error
                $codepoint = 0xFFFD;
            }

            /* Otherwise, return a character token for the Unicode
            character whose code point is that number. */
            return HTML5_Data::utf8chr($codepoint);

        } else {
            /* Anything else */
            
            /* Consume the maximum number of characters possible,
            with the consumed characters matching one of the
            identifiers in the first column of the named character
            references table (in a case-sensitive manner). */
            
            // we will implement this by matching the longest
            // alphanumeric + semicolon string, and then working
            // our way backwards
            
            $consumed = $this->characters('0-9A-Za-z;', $this->char + 1);
            $len = strlen($consumed);
            $start = $this->char;

            $refs = HTML5_Data::getNamedCharacterReferences();
            $codepoint = false;
            for($c = $len; $c > 0; $c--) {
                $id = substr($consumed, 0, $c);
                if(isset($refs[$id])) {
                    $codepoint = $refs[$id];
                    break;
                }
            }

            /* If no match can be made, then this is a parse error.
            No characters are consumed, and nothing is returned. */
            if (!$codepoint) return false;
            
            /* If the last character matched is not a U+003B SEMICOLON
            (;), there is a parse error. */
            $this->char += $c;
            $semicolon = true;
            if (substr($id, -1) !== ';') {
                // parse error
                $semicolon = false;
            }
            

            /* If the character reference is being consumed as part of
            an attribute, and the last character matched is not a
            U+003B SEMICOLON (;), and the next character is in the
            range U+0030 DIGIT ZERO to U+0039 DIGIT NINE, U+0041
            LATIN CAPITAL LETTER A to U+005A LATIN CAPITAL LETTER Z,
            or U+0061 LATIN SMALL LETTER A to U+007A LATIN SMALL LETTER Z,
            then, for historical reasons, all the characters that were
            matched after the U+0026 AMPERSAND (&) must be unconsumed,
            and nothing is returned. */
            if (
                $inattr && !$semicolon &&
                $this->char + 1 !== $this->EOF &&
                preg_match('/^[0-9A-Za-z]$/', $this->character($this->char + 1))
            ) {
                $this->char = $start;
                return false;
            }

            /* Otherwise, return a character token for the character
            corresponding to the character reference name (as given
            by the second column of the named character references table). */
            return HTML5_Data::utf8chr($codepoint);
        }
    }

    /**
     * Emits a token, passing it on to the tree builder.
     */
    protected function emitToken($token) {
        // the current structure of attributes is not a terribly good one
        $emit = $this->tree->emitToken($token);

        if(is_int($emit)) {
            $this->content_model = $emit;

        } elseif($token['type'] === self::ENDTAG) {
            $this->content_model = self::PCDATA;
        }
    }

    /**
     * Emits the end of file token, and signals the end of tokenization.
     */
    private function EOF() {
        $this->state = null;
        $this->tree->emitToken(array(
            'type' => self::EOF
        ));
    }
}

