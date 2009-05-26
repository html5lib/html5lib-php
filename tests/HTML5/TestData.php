<?php

/**
 * Interface for retreiving test files. Also represents a .dat file.
 */
class HTML5_TestData
{
    /**
     * Retrieves a list of test filenames from a directory.
     */
    static public function getList($type, $glob) {
        $full_glob =
            realpath(dirname(__FILE__) . '/../../../testdata/' . $type) .
            DIRECTORY_SEPARATOR . $glob;
        return glob($full_glob);
    }
    /**
     * This function generates unique test case classes corresponding
     * to test files in the testdata directory.
     */
    static public function generateTestCases($base, $prefix, $type, $glob) {
        foreach (HTML5_TestData::getList($type, $glob) as $filename) {
            $name = str_replace('-', '', basename($filename));
            $name = ucfirst(substr($name, 0, strcspn($name, '.')));
            $pfilename = var_export($filename, true);
            $code = "class $prefix$name extends $base { public \$filename = $pfilename; }";
            eval($code);
        }
    }
    
    public $tests;
    
    public function __construct($filename) {
        $test = array();
        $newTestHeading = null;
        $heading = null;
        foreach (explode("\n", file_get_contents($filename)) as $line) {
            if ($line !== '' && $line[0] === '#') {
                $newHeading = substr($line, 1);
                if (!$newTestHeading) {
                    $newTestHeading = $newHeading;
                } elseif ($newHeading === $newTestHeading) {
                    $test[$heading] = substr($test[$heading], 0, -1);
                    $this->tests[] = $test;
                    $test = array();
                }
                $heading = $newHeading;
                $test[$heading] = '';
            } elseif ($heading) {
                $test[$heading] .= "$line\n";
            }
        }
        if (!empty($test)) {
            $test[$heading] = substr($test[$heading], 0, -1);
            $this->tests[] = $test;
        }
    }

    /**
     * Converts a DOMDocument into string form as seen in test cases.
     */
    public static function strDom($dom, $prefix = '| ') {
        $ret = array();
        $indent = 2;
        $level  = -1; // since DOMDocument doesn't get rendered
        $skip = false;
        $next = $dom;
        while ($next) {
            $text = false;
            $subnodes = array();
            switch ($next->nodeType) {
                case XML_DOCUMENT_NODE:
                case XML_HTML_DOCUMENT_NODE:
                    if ($next->doctype) {
                        $subnode = '<!DOCTYPE ';
                        $subnode .= $next->doctype->name;
                        if ($next->doctype->publicId) {
                            $subnode .= ' "' . $next->doctype->publicId . '"';
                        }
                        if ($next->doctype->systemId) {
                            $subnode .= ' "' . $next->doctype->systemId . '"';
                        }
                        $subnode .= '>';
                        $subnodes[] = $subnode;
                    }
                    break;
                case XML_TEXT_NODE:
                    $text = '"' . $next->data . '"';
                    break;
                case XML_COMMENT_NODE:
                    $text = "<!-- {$next->data} -->";
                    break;
                case XML_ELEMENT_NODE:
                    $text = "<{$next->tagName}>";
                    foreach ($next->attributes as $attr) {
                        $subnodes[] = "{$attr->name}=\"{$attr->value}\"";
                    }
                    sort($subnodes);
                    break;
            }
            if (!$skip) {
                // code duplication
                if ($text) {
                    $ret[] = $prefix . str_repeat(' ', $indent * $level) . $text;
                }
                foreach ($subnodes as $node) {
                    $ret[] = $prefix . str_repeat(' ', $indent * ($level + 1)) . $node;
                }
            }
            if ($next->firstChild && !$skip) {
                $next = $next->firstChild;
                $level++;
                $skip = false;
            } elseif ($next->nextSibling) {
                $next = $next->nextSibling;
                $skip = false;
            } elseif ($next->parentNode) {
                $next = $next->parentNode;
                $level--;
                $skip = true;
            } else {
                $next = false;
            }
        }
        return implode("
", $ret);
    }
}
