<?php

require_once(__DIR__.'/ObjectFormatCFTest.php');

class ObjectFormatCounterCFTest extends ObjectFormatCFTest {

    protected static $CF = "Counter1";

    protected static $cfattrs = array(
        "column_type" => "Standard",
        "default_validation_class" => "CounterColumnType"
    );

    protected $cols = array(array('col1', 'val1'), array('col2', 'val2'));

    public function test_indexed_slices() { }
}