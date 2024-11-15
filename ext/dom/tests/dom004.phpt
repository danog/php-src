--TEST--
Test 4: Streams Test
--EXTENSIONS--
dom
--SKIPIF--
<?php
in_array('compress.zlib', stream_get_wrappers()) or die('skip compress.zlib wrapper is not available');
?>
--FILE--
<?php
$dom = new domdocument;
$dom->load("compress.zlib://" . str_replace("\\", "/", __DIR__) . "/book.xml.gz");
print $dom->saveXML();
?>
--EXPECT--
<?xml version="1.0"?>
<books>
 <book>
  <title>The Grapes of Wrath</title>
  <author>John Steinbeck</author>
 </book>
 <book>
  <title>The Pearl</title>
  <author>John Steinbeck</author>
 </book>
</books>
