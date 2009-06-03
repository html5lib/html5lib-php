<?php
require_once dirname(__FILE__) . '/../library/HTML5/Parser.php';
$argv = $_SERVER['argv'];
if (!isset($argv[1])) {
    $file = 'php://stdin';
} else {
    $file = $argv[1];
}
$result = HTML5_Parser::parse(file_get_contents($file));
// nop
