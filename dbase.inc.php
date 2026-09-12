<?php
/**
 * The dBase functions, for where the extension is not installed.
 *
 * This file is loaded on every request, so it holds nothing but the constants and the
 * functions themselves. The work is in the DBase class, which the autoloader fetches the
 * first time one of them is called - and never at all where the extension answers instead.
 */
define('DBASE_RDONLY', 0);
define('DBASE_WRONLY', 1);
define('DBASE_RDWR',   2);

define('DBASE_TYPE_DBASE',  0);
define('DBASE_TYPE_FOXPRO', 1);

if(!function_exists('dbase_open')) {
	function dbase_open($filename, $mode) { return DBase::open($filename, $mode); }
	function dbase_create($filename, $fields, $type = DBASE_TYPE_DBASE) { return DBase::create($filename, $fields, $type); }
	function dbase_close($dbase_identifier) { return $dbase_identifier->close(); }
	function dbase_get_header_info($dbase_identifier) { return $dbase_identifier->get_header_info(); }
	function dbase_numfields($dbase_identifier) { $dbase_identifier->numfields(); }
	function dbase_numrecords($dbase_identifier) { return $dbase_identifier->numrecords(); }
	function dbase_add_record($dbase_identifier, $record) { return $dbase_identifier->add_record($record); }
	function dbase_delete_record($dbase_identifier, $record_number) { return $dbase_identifier->delete_record($record_number); }
	function dbase_replace_record($dbase_identifier, $record, $record_number) { return $dbase_identifier->replace_record($record, $record_number); }
	function dbase_get_record($dbase_identifier, $record_number) { return $dbase_identifier->get_record($record_number); }
	function dbase_get_record_with_names($dbase_identifier, $record_number) { return $dbase_identifier->get_record_with_names($record_number); }
	function dbase_pack($dbase_identifier) { return $dbase_identifier->pack(); }
}
