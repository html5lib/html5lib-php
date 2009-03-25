<?php

/**
 * This script scrapes named character references from the WHATWG
 * website.
 */

$output = dirname(__FILE__) . '/../library/HTML5/named-character-references.ser';
if (file_exists($output)) {
    echo 'Output file '.realpath($output).' already exists; delete it first';
    exit;
}

$url = 'http://www.whatwg.org/specs/web-apps/current-work/multipage/named-character-references.html';
$request = new HttpRequest($url);
$request->send();
$html = $request->getResponseBody();

preg_match_all(
    '#<code title="">\s*([^<]+?)\s*</code>\s*</td>\s*<td>\s*U+([^<]+?)\s*<#',
    $html, $matches, PREG_SET_ORDER);

$table = array();
foreach ($matches as $match) {
    $ncr = $match[1];
    $codepoint = hexdec($match[2]);
    $table[$ncr] = $codepoint;
}

file_put_contents($output, serialize($table));
