<?php

/**
 * The dBase functions, for where the extension is not installed.
 *
 * This file is loaded on every request, so it holds nothing but the constants and the functions
 * themselves. The work is in the DBase class, which the autoloader fetches the first time one of
 * them is called - and never at all where the extension is there to answer instead.
 */

use Mapik\DBase\DBase;

if (!defined('DBASE_RDONLY')) {
    define('DBASE_RDONLY', 0);
}
if (!defined('DBASE_WRONLY')) {
    define('DBASE_WRONLY', 1);
}
if (!defined('DBASE_RDWR')) {
    define('DBASE_RDWR', 2);
}

if (!defined('DBASE_TYPE_DBASE')) {
    define('DBASE_TYPE_DBASE', 0);
}
if (!defined('DBASE_TYPE_FOXPRO')) {
    define('DBASE_TYPE_FOXPRO', 1);
}

if (!function_exists('dbase_open')) {
    /**
     * @return DBase|false
     */
    function dbase_open(string $filename, int $mode)
    {
        return DBase::open($filename, $mode);
    }

    /**
     * @param list<array<int, mixed>> $fields
     * @return DBase|false
     */
    function dbase_create(string $filename, array $fields, int $type = DBASE_TYPE_DBASE)
    {
        return DBase::create($filename, $fields, $type);
    }

    function dbase_close(DBase $dbase_identifier): bool
    {
        return $dbase_identifier->close();
    }

    /**
     * @return list<array{name: string, type: string, length: int, precision: int, format: string, offset: int}>
     */
    function dbase_get_header_info(DBase $dbase_identifier): array
    {
        return $dbase_identifier->get_header_info();
    }

    function dbase_numfields(DBase $dbase_identifier): int
    {
        return $dbase_identifier->numfields();
    }

    function dbase_numrecords(DBase $dbase_identifier): int
    {
        return $dbase_identifier->numrecords();
    }

    /**
     * @param array<int, mixed> $record
     */
    function dbase_add_record(DBase $dbase_identifier, array $record): bool
    {
        return $dbase_identifier->add_record($record);
    }

    function dbase_delete_record(DBase $dbase_identifier, int $record_number): bool
    {
        return $dbase_identifier->delete_record($record_number);
    }

    /**
     * @param array<int, mixed> $record
     */
    function dbase_replace_record(DBase $dbase_identifier, array $record, int $record_number): bool
    {
        return $dbase_identifier->replace_record($record, $record_number);
    }

    /**
     * @return array<int|string, mixed>|false
     */
    function dbase_get_record(DBase $dbase_identifier, int $record_number)
    {
        return $dbase_identifier->get_record($record_number);
    }

    /**
     * @return array<string, mixed>|false
     */
    function dbase_get_record_with_names(DBase $dbase_identifier, int $record_number)
    {
        return $dbase_identifier->get_record_with_names($record_number);
    }

    function dbase_pack(DBase $dbase_identifier): bool
    {
        return $dbase_identifier->pack();
    }
}
