<?php

/**
 * The dBase functions, for where the extension is not installed.
 *
 * This file is loaded on every request, so it holds nothing but the constants and the functions
 * themselves. The work is in the DBase class, which the autoloader fetches the first time one of
 * them is called - and never at all where the extension is there to answer instead.
 *
 * The handle a function is given is left untyped on purpose. The extension hands out a resource
 * and this hands out an object, and no type declaration covers both - so an application written
 * to work either way would be told off by a static analyser for whichever one it did not have
 * installed. The shape is said in the docblock instead, and the object is asked for at the door.
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

    /**
     * @param DBase|resource $dbase_identifier
     */
    function dbase_close($dbase_identifier): bool
    {
        return DBase::given($dbase_identifier, __FUNCTION__)->close();
    }

    /**
     * @param DBase|resource $dbase_identifier
     * @return list<array{name: string, type: string, length: int, precision: int, format: string, offset: int}>
     */
    function dbase_get_header_info($dbase_identifier): array
    {
        return DBase::given($dbase_identifier, __FUNCTION__)->get_header_info();
    }

    /**
     * @param DBase|resource $dbase_identifier
     */
    function dbase_numfields($dbase_identifier): int
    {
        return DBase::given($dbase_identifier, __FUNCTION__)->numfields();
    }

    /**
     * @param DBase|resource $dbase_identifier
     */
    function dbase_numrecords($dbase_identifier): int
    {
        return DBase::given($dbase_identifier, __FUNCTION__)->numrecords();
    }

    /**
     * @param DBase|resource $dbase_identifier
     * @param array<int, mixed> $record
     */
    function dbase_add_record($dbase_identifier, array $record): bool
    {
        return DBase::given($dbase_identifier, __FUNCTION__)->add_record($record);
    }

    /**
     * @param DBase|resource $dbase_identifier
     */
    function dbase_delete_record($dbase_identifier, int $record_number): bool
    {
        return DBase::given($dbase_identifier, __FUNCTION__)->delete_record($record_number);
    }

    /**
     * @param DBase|resource $dbase_identifier
     * @param array<int, mixed> $record
     */
    function dbase_replace_record($dbase_identifier, array $record, int $record_number): bool
    {
        return DBase::given($dbase_identifier, __FUNCTION__)->replace_record($record, $record_number);
    }

    /**
     * @param DBase|resource $dbase_identifier
     * @return array<int|string, mixed>|false
     */
    function dbase_get_record($dbase_identifier, int $record_number)
    {
        return DBase::given($dbase_identifier, __FUNCTION__)->get_record($record_number);
    }

    /**
     * @param DBase|resource $dbase_identifier
     * @return array<string, mixed>|false
     */
    function dbase_get_record_with_names($dbase_identifier, int $record_number)
    {
        return DBase::given($dbase_identifier, __FUNCTION__)->get_record_with_names($record_number);
    }

    /**
     * @param DBase|resource $dbase_identifier
     */
    function dbase_pack($dbase_identifier): bool
    {
        return DBase::given($dbase_identifier, __FUNCTION__)->pack();
    }
}
