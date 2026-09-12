<?php

/**
 * A pure PHP implementation of the dBase functions.
 *
 * Loaded only when something reaches for it, which on an installation that has the extension is
 * never. Where the manual and the extension disagree, the extension wins: this is here to stand
 * in for it, not to improve on it.
 *
 * @see https://secure.php.net/manual/en/ref.dbase.php
 */

namespace Mapik\DBase;

/**
 * @phpstan-type FieldDefinition array<int, mixed>
 * @phpstan-type Field array{name: string, type: string, length: int, precision: int, offset: int}
 */
class DBase
{
    /**
     * The byte a dBASE file ends with. It is written when the file is made and kept at the end
     * as records are added, because that is where the extension keeps it.
     */
    public const EOF_MARKER = 0x1A;

    /**
     * @var resource
     */
    private $fd;

    private int $headerLength = 0;

    /**
     * @var list<Field>
     */
    private array $fields = array();

    private int $fieldCount = 0;

    private int $recordLength = 0;
    private int $recordCount = 0;

    /**
     * resource dbase_open ( string $filename , int $mode )
     *
     * @return self|false
     */
    public static function open(string $filename, int $mode)
    {
        if (!file_exists($filename)) {
            return false;
        }

        // Write-only opens the file as it stands. 'w' would empty it, which is not what asking
        // to write to a database means.
        $modes = array('r', 'r+', 'r+');
        if (!isset($modes[$mode])) {
            return false;
        }

        $fd = fopen($filename, $modes[$mode]);
        if (!$fd) {
            return false;
        }

        return new DBase($fd);
    }

    /**
     * resource dbase_create ( string $filename , array $fields [, int $type = DBASE_TYPE_DBASE ] )
     *
     * @param list<FieldDefinition> $fields
     * @return self|false
     */
    public static function create(string $filename, array $fields, int $type = DBASE_TYPE_DBASE)
    {
        if (file_exists($filename)) {
            return false;
        }
        if (count($fields) === 0) {
            return false;
        }

        $fd = fopen($filename, 'c+');
        if (!$fd) {
            return false;
        }

        // Byte 0 (1 byte): Valid dBASE for DOS file; bits 0-2 indicate version number, bit 3
        // indicates the presence of a dBASE for DOS memo file, bits 4-6 indicate the
        // presence of a SQL table, bit 7 indicates the presence of any memo file
        // (either dBASE m PLUS or dBASE for DOS)
        self::putChar8($fd, 3);

        // Byte 1-3 (3 bytes): Date of last update; formatted as YYMMDD
        self::putChar8($fd, (int)date('Y') - 1900);
        self::putChar8($fd, (int)date('m'));
        self::putChar8($fd, (int)date('d'));

        // Byte 4-7 (32-bit number): Number of records in the database file.  Currently 0
        self::putInt32($fd, 0);

        // Byte 8-9 (16-bit number): Number of bytes in the header.
        self::putInt16($fd, 32 + (32 * count($fields)) + 1);

        // Byte 10-11 (16-bit number): Number of bytes in record.
        // Make sure the include the byte for deleted flag
        $len = 1;
        foreach ($fields as $field) {
            $len += self::length($field);
        }
        self::putInt16($fd, $len);

        // Byte 12-13 (2 bytes): Reserved, 0 filled.
        self::putInt16($fd, 0);

        // Byte 14 (1 byte): Flag indicating incomplete transaction
        // The ISMARKEDO function checks this flag. BEGIN TRANSACTION sets it to 1,
        // END TRANSACTION and ROLLBACK reset it to 0.
        self::putChar8($fd, 0);

        // Byte 15 (1 byte): Encryption flag. If this flag is set to 1, the message Database
        // encrypted appears. Changing this flag to 0 removes the message, but does not decrypt
        // the file.
        self::putChar8($fd, 0);

        // Byte 16-27 (12 bytes): Reserved for dBASE for DOS in a multi-user environment
        self::putInt32($fd, 0);
        self::putInt32($fd, 0);
        self::putInt32($fd, 0);

        // Byte 28 (1 byte): Production .mdx file flag; 0x01 if there is a production .mdx file, 0x00 if not
        self::putChar8($fd, 0);

        // Byte 29 (1 byte): Language driver ID
        // (no clue what this is)
        self::putChar8($fd, 0);

        // Byte 30-31 (2 bytes): Reserved, 0 filled.
        self::putInt16($fd, 0);

        // Byte 32 - n (32 bytes each): Field descriptor array
        foreach ($fields as $field) {
            // Byte 0 - 10 (11 bytes): Field name in ASCII (zero-filled)
            self::putStringNull($fd, (string)$field[0], 11);

            // Byte 11 (1 byte): Field type in ASCII (C, D, F, L, M, or N)
            self::putString($fd, (string)$field[1], 1);

            // Byte 12 - 15 (4 bytes): Reserved
            self::putInt32($fd, 0);

            // Byte 16 (1 byte): Field length in binary. The longest a field may be is 254 (0xFE).
            self::putChar8($fd, self::length($field));

            // Byte 17 (1 byte): Field decimal count in binary
            self::putChar8($fd, self::precision($field));

            // Byte 18 - 19 (2 bytes): Work area ID
            self::putInt16($fd, 0);

            // Byte 20 (1 byte): Example (??)
            self::putChar8($fd, 0);

            // Byte 21 - 30 (10 bytes): Reserved
            self::putInt32($fd, 0);
            self::putInt32($fd, 0);
            self::putInt16($fd, 0);

            // Byte 31 (1 byte): Production MDX field flag; 1 if the field has an index tag in
            // the production MDX file, 0 if not
            self::putChar8($fd, 0);
        }

        // Byte n + 1 (1 byte): 0x0D as the field descriptor array terminator
        self::putChar8($fd, 0x0D);

        // And the end of file marker, which an empty database carries just as a full one does
        self::putChar8($fd, self::EOF_MARKER);

        return new DBase($fd);
    }

    /**
     * Create DBase instance
     *
     * @param resource $fd
     */
    private function __construct($fd)
    {
        $this->fd = $fd;

        // Byte 4-7 (32-bit number): Number of records in the database file.  Currently 0
        fseek($this->fd, 4, SEEK_SET);
        $this->recordCount = self::getInt32($fd);

        // Byte 8-9 (16-bit number): Number of bytes in the header.
        fseek($this->fd, 8, SEEK_SET);
        $this->headerLength = self::getInt16($fd);

        // Number of fields is (headerLength - 33) / 32)
        $this->fieldCount = intdiv($this->headerLength - 33, 32);

        // Byte 10-11 (16-bit number): Number of bytes in record.
        fseek($this->fd, 10, SEEK_SET);
        $this->recordLength = self::getInt16($fd);

        // Byte 32 - n (32 bytes each): Field descriptor array
        fseek($fd, 32, SEEK_SET);
        $offset = 1;
        for ($i = 0; $i < $this->fieldCount; $i++) {
            // unsigned length and precision
            $read = unpack(
                'a11name/a1type/c4/C1length/C1precision/s1workid/c1example/c10/c1production',
                self::read($this->fd, 32),
            );
            if ($read === false) {
                break;
            }

            $field = array(
                'name'      => trim((string)$read['name'], "\0 "),
                'type'      => trim((string)$read['type']),
                'length'    => (int)$read['length'],
                'precision' => (int)$read['precision'],
                'offset'    => $offset,
            );
            $offset += $field['length'];

            $this->fields[] = $field;
        }
    }

    /**
     * The handle one of the functions was given, as the object it is really about.
     *
     * Those functions leave the parameter untyped, so that code written against the extension -
     * where a handle is a resource - is accepted by a static analyser whichever of the two is
     * installed. What actually arrives can still only be one of these, and anything else is the
     * same mistake the extension would have refused.
     *
     * @param mixed $given Whatever the caller passed.
     * @param string $called What it passed it to, for the message.
     * @return self
     */
    public static function given($given, string $called): self
    {
        if ($given instanceof self) {
            return $given;
        }

        throw new \TypeError(sprintf(
            '%s(): Argument #1 ($dbase_identifier) must be of type %s, %s given',
            $called,
            self::class,
            get_debug_type($given),
        ));
    }

    /**
     * bool dbase_close ( resource $dbase_identifier )
     */
    public function close(): bool
    {
        return fclose($this->fd);
    }

    /**
     * array dbase_get_header_info ( resource $dbase_identifier )
     *
     * @return list<array{name: string, type: string, length: int, precision: int, format: string, offset: int}>
     */
    public function get_header_info(): array
    {
        $info = array();

        foreach ($this->fields as $field) {
            $info[] = array(
                'name'      => $field['name'],
                'type'      => self::typeName($field['type']),
                'length'    => $field['length'],
                'precision' => $field['precision'],
                'format'    => $field['type'] === 'C'
                    ? '%-' . $field['length'] . 's'
                    : '%' . $field['length'] . 's',
                'offset'    => $field['offset'],
            );
        }

        return $info;
    }

    /**
     * int dbase_numfields ( resource $dbase_identifier )
     */
    public function numfields(): int
    {
        return $this->fieldCount;
    }

    /**
     * int dbase_numrecords ( resource $dbase_identifier )
     */
    public function numrecords(): int
    {
        return $this->recordCount;
    }

    /**
     * bool dbase_add_record ( resource $dbase_identifier , array $record )
     *
     * @param array<int, mixed> $record
     */
    public function add_record(array $record): bool
    {
        if (count($record) != $this->fieldCount) {
            return false;
        }

        // Seek to end of file, minus the end of file marker
        fseek($this->fd, -1, SEEK_END);

        // Put the deleted flag
        self::putChar8($this->fd, 0x20);

        // Put the record
        if (!$this->putRecord($record)) {
            return false;
        }

        // And put the marker back on the end
        self::putChar8($this->fd, self::EOF_MARKER);

        // Update the record count
        fseek($this->fd, 4);
        self::putInt32($this->fd, ++$this->recordCount);
        return true;
    }

    /**
     * bool dbase_replace_record ( resource $dbase_identifier , array $record , int $record_number )
     *
     * @param array<int, mixed> $record
     */
    public function replace_record(array $record, int $record_number): bool
    {
        if (count($record) != $this->fieldCount) {
            return false;
        }
        if ($record_number < 1 || $record_number > $this->recordCount) {
            return false;
        }

        // Skip to the record location, plus the 1 byte for the deleted flag
        fseek($this->fd, $this->headerLength + ($this->recordLength * ($record_number - 1)) + 1);
        return $this->putRecord($record);
    }

    /**
     * bool dbase_delete_record ( resource $dbase_identifier , int $record_number )
     */
    public function delete_record(int $record_number): bool
    {
        if ($record_number < 1 || $record_number > $this->recordCount) {
            return false;
        }

        fseek($this->fd, $this->headerLength + ($this->recordLength * ($record_number - 1)));
        self::putChar8($this->fd, 0x2A);
        return true;
    }

    /**
     * array dbase_get_record ( resource $dbase_identifier , int $record_number )
     *
     * @return array<int|string, mixed>|false
     */
    public function get_record(int $record_number)
    {
        if ($record_number < 1 || $record_number > $this->recordCount) {
            return false;
        }

        fseek($this->fd, $this->headerLength + ($this->recordLength * ($record_number - 1)));

        $deleted = self::getChar8($this->fd) == 0x2A ? 1 : 0;

        $record = array();
        foreach ($this->fields as $i => $field) {
            $record[$i] = self::castOut(self::read($this->fd, $field['length']), $field);
        }

        // The extension puts the flag after the fields, not before them
        $record['deleted'] = $deleted;

        return $record;
    }

    /**
     * array dbase_get_record_with_names ( resource $dbase_identifier , int $record_number )
     *
     * @return array<string, mixed>|false
     */
    public function get_record_with_names(int $record_number)
    {
        $record = $this->get_record($record_number);
        if ($record === false) {
            return false;
        }

        $named = array();
        foreach ($this->fields as $i => $field) {
            $named[$field['name']] = $record[$i];
        }
        $named['deleted'] = $record['deleted'];

        return $named;
    }

    /**
     * bool dbase_pack ( resource $dbase_identifier )
     */
    public function pack(): bool
    {
        $in_offset = $out_offset = $this->headerLength;

        $new_count = 0;
        $rec_count = $this->recordCount;

        while ($rec_count > 0) {
            fseek($this->fd, $in_offset, SEEK_SET);
            $record = self::read($this->fd, $this->recordLength);

            $deleted = substr($record, 0, 1);
            if ($deleted != '*') {
                fseek($this->fd, $out_offset, SEEK_SET);
                fwrite($this->fd, $record);

                $out_offset += $this->recordLength;
                $new_count++;
            }

            $in_offset += $this->recordLength;
            $rec_count--;
        }

        // The marker goes back on the end, so a packed file still ends the way a file should
        ftruncate($this->fd, max(0, $out_offset));
        fseek($this->fd, $out_offset, SEEK_SET);
        self::putChar8($this->fd, self::EOF_MARKER);

        // Update the record count
        fseek($this->fd, 4);
        self::putInt32($this->fd, $new_count);

        $this->recordCount = $new_count;
        return true;
    }

    /*
     * A few utilitiy functions
     */

    /**
     * @param FieldDefinition $field
     */
    private static function length(array $field): int
    {
        switch ($field[1]) {
            // Date: Numbers and a character to separate month, day, and year (stored
            // internally as 8 digits in YYYYMMDD format)
            case 'D':
                return 8;

            case 'T': // DateTime (YYYYMMDDhhmmss.uuu) (FoxPro)
                return 18;

            // Memo (ignored): All ASCII characters (stored internally as 10 digits
            // representing a .dbt block number, right justified, padded with whitespaces)
            case 'M':
            case 'N': // Number: -.0123456789 (right justified, padded with whitespaces)
            case 'F': // Float: -.0123456789 (right justified, padded with whitespaces)
            case 'C': // String: All ASCII characters (padded with whitespaces up to the field's length)
                return isset($field[2]) ? (int)$field[2] : 0;

            case 'L': // Boolean: YyNnTtFf? (? when not initialized)
                return 1;
        }

        return 0;
    }

    /**
     * How many decimals a field keeps. Only the numeric types are given one, and the rest of the
     * definition simply stops before it.
     *
     * @param FieldDefinition $field
     */
    private static function precision(array $field): int
    {
        if (!self::isNumeric((string)$field[1])) {
            return 0;
        }

        return isset($field[3]) ? (int)$field[3] : 0;
    }

    private static function isNumeric(string $type): bool
    {
        return $type === 'N' || $type === 'F';
    }

    private static function typeName(string $type): string
    {
        switch ($type) {
            case 'C':
                return 'character';
            case 'D':
                return 'date';
            case 'N':
                return 'number';
            case 'F':
                return 'float';
            case 'L':
                return 'boolean';
            case 'M':
                return 'memo';
            case 'T':
                return 'datetime';
        }

        return 'unknown';
    }

    /**
     * Turns a value into the characters the field holds.
     *
     * The extension goes by the type of what it is handed rather than by the field: a float is
     * written to the field's decimals, an integer as it stands, and a string untouched. That is
     * why 0 and 0.0 come out differently, and it has to be matched rather than tidied up.
     *
     * @param Field $field
     */
    private static function castIn(mixed $value, array $field): string
    {
        if ($value === null) {
            return '';
        }

        if (self::isNumeric($field['type'])) {
            // Rounded first, because sprintf() alone would read 1.005 as the binary value just under it
            if (is_float($value)) {
                return sprintf('%.' . $field['precision'] . 'f', round($value, $field['precision']));
            }

            return is_scalar($value) ? (string)$value : '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * Turns the characters a field holds back into a value, the way the extension does: a number
     * with no decimals is an integer, one with decimals a float, and text is handed back padded
     * exactly as it is stored.
     *
     * @param Field $field
     */
    private static function castOut(string $raw, array $field): mixed
    {
        if (self::isNumeric($field['type'])) {
            return $field['precision'] > 0 ? (float)trim($raw) : (int)trim($raw);
        }

        if ($field['type'] === 'L') {
            $value = strtolower(trim($raw));
            if ($value === 't' || $value === 'y') {
                return true;
            }
            if ($value === '?') {
                return null;
            }

            return false;
        }

        return $raw;
    }

    /*
     * Functions for reading and writing bytes
     */

    /**
     * Reads the bytes asked for, and answers with what there was.
     *
     * A short read means a file that has been cut off, which is a broken database rather than
     * something to be recovered from here. The callers are all working to the lengths the header
     * gave them, so this only ever comes up on one.
     *
     * @param resource $fd
     */
    private static function read($fd, int $length): string
    {
        if ($length < 1) {
            return '';
        }

        return (string)fread($fd, $length);
    }

    /**
     * @param resource $fd
     */
    private static function getChar8($fd): int
    {
        return ord(self::read($fd, 1));
    }

    /**
     * @param resource $fd
     */
    private static function putChar8($fd, int $value): void
    {
        // A byte is a byte. chr() wraps anything wider by itself, and saying so here is what
        // lets the header be written from counts without each one being checked first.
        fwrite($fd, chr($value & 0xFF));
    }

    /**
     * @param resource $fd
     */
    private static function getInt16($fd): int
    {
        $read = unpack('S', self::read($fd, 2));

        return $read === false ? 0 : (int)$read[1];
    }

    /**
     * @param resource $fd
     */
    private static function putInt16($fd, int $value): void
    {
        fwrite($fd, pack('S', $value));
    }

    /**
     * @param resource $fd
     */
    private static function getInt32($fd): int
    {
        $read = unpack('L', self::read($fd, 4));

        return $read === false ? 0 : (int)$read[1];
    }

    /**
     * @param resource $fd
     */
    private static function putInt32($fd, int $value): void
    {
        fwrite($fd, pack('L', $value));
    }

    /**
     * @param resource $fd
     */
    private static function putString($fd, string $value, int $length = 254): void
    {
        fwrite($fd, pack('A' . $length, $value));
    }

    /**
     * @param resource $fd
     */
    private static function putStringNull($fd, string $value, int $length = 254): void
    {
        fwrite($fd, pack('a' . $length, $value));
    }

    /**
     * @param array<int, mixed> $record
     */
    private function putRecord(array $record): bool
    {
        foreach ($this->fields as $i => $field) {
            $value = self::castIn($record[$i] ?? null, $field);

            // Numbers sit at the right hand end of the field, text at the left
            if (self::isNumeric($field['type'])) {
                $value = substr(str_pad($value, $field['length'], ' ', STR_PAD_LEFT), -$field['length']);
            }

            self::putString($this->fd, $value, $field['length']);
        }

        return true;
    }
}
