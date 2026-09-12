<?php

declare(strict_types=1);

namespace Mapik\DBase\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The same database written both ways, held against each other byte for byte.
 *
 * This is the only test that can say whether the stand-in is one, so where the extension is
 * missing it skips rather than passing quietly and saying nothing.
 */
class ExtensionParityTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('dbase')) {
            $this->markTestSkipped('There is nothing to compare against without ext-dbase.');
        }
    }

    /**
     * @return iterable<string, array{list<array<int, mixed>>, list<array<int, mixed>>}>
     */
    public static function databases(): iterable
    {
        yield 'nothing in it' => [[['NAME', 'C', 10]], []];

        yield 'text' => [
            [['NAME', 'C', 10]],
            [['abc'], ['far too long to fit'], [''], [null], [42]],
        ];

        // The case the whole thing turns on: an integer and a float in one and the same field
        yield 'money' => [
            [['AMOUNT', 'N', 8, 2]],
            [[-7.5], [0], [1234.56], [3], ['5'], ['5.1'], [1.005], [null], [0.0]],
        ];

        yield 'whole numbers' => [
            [['N', 'N', 6, 0]],
            [[42], [42.7], ['42'], [null]],
        ];

        yield 'floats' => [[['F', 'F', 8, 2]], [[1.5], [2]]];

        yield 'logicals' => [
            [['L', 'L']],
            [[true], [false], ['T'], ['F'], ['?'], [null]],
        ];

        yield 'dates' => [[['D', 'D']], [['20260912'], [null]]];

        yield 'one of each' => [
            [['S', 'C', 5], ['D', 'D'], ['N', 'N', 6, 0], ['L', 'L'], ['M', 'N', 10, 2]],
            [['ab', '20260912', 42, 'T', 19.9], ['cd', '20260101', -1, 'F', 0]],
        ];
    }

    /**
     * @param list<array<int, mixed>> $structure
     * @param list<array<int, mixed>> $records
     */
    #[DataProvider('databases')]
    public function testTheFileIsTheSameFileEitherWay(array $structure, array $records): void
    {
        [$theirs, $ours] = $this->build($structure, $records);

        $this->assertSameBytes($theirs, $ours);
    }

    /**
     * @param list<array<int, mixed>> $structure
     * @param list<array<int, mixed>> $records
     */
    #[DataProvider('databases')]
    public function testWhatComesBackIsTheSameEitherWay(array $structure, array $records): void
    {
        [$theirs, $ours] = $this->build($structure, $records);

        $theirDb = dbase_open($theirs, DBASE_RDONLY);
        $this->assertNotFalse($theirDb);
        $ourDb = $this->open($ours);

        $this->assertSame(dbase_numfields($theirDb), $ourDb->numfields());
        $this->assertSame(dbase_numrecords($theirDb), $ourDb->numrecords());
        $this->assertSame(dbase_get_header_info($theirDb), $ourDb->get_header_info());

        for ($i = 1; $i <= count($records); $i++) {
            $this->assertSame(
                dbase_get_record_with_names($theirDb, $i),
                $ourDb->get_record_with_names($i),
                'record ' . $i,
            );
            $this->assertSame(
                dbase_get_record($theirDb, $i),
                $ourDb->get_record($i),
                'record ' . $i . ' by position',
            );
        }

        dbase_close($theirDb);
        $ourDb->close();
    }

    public function testDeletingAndPackingLeaveTheSameFile(): void
    {
        [$theirs, $ours] = $this->build([['A', 'C', 4]], [['one'], ['two'], ['ten']]);

        $theirDb = dbase_open($theirs, DBASE_RDWR);
        $this->assertNotFalse($theirDb);
        $ourDb = $this->open($ours, DBASE_RDWR);

        dbase_delete_record($theirDb, 2);
        $ourDb->delete_record(2);
        $this->assertSameBytes($theirs, $ours, 'after marking one as gone');

        dbase_pack($theirDb);
        $ourDb->pack();
        dbase_close($theirDb);
        $ourDb->close();

        $this->assertSameBytes($theirs, $ours, 'after packing');
    }

    public function testReplacingARecordLeavesTheSameFile(): void
    {
        [$theirs, $ours] = $this->build(
            [['A', 'C', 4], ['B', 'N', 6, 2]],
            [['one', 1.0], ['two', 2.5]],
        );

        $theirDb = dbase_open($theirs, DBASE_RDWR);
        $this->assertNotFalse($theirDb);
        $ourDb = $this->open($ours, DBASE_RDWR);

        dbase_replace_record($theirDb, ['six', 6.75], 1);
        $ourDb->replace_record(['six', 6.75], 1);

        dbase_close($theirDb);
        $ourDb->close();

        $this->assertSameBytes($theirs, $ours);
    }

    /**
     * Compared as hexadecimal, so that a failure names the byte rather than printing the file.
     */
    private function assertSameBytes(string $theirs, string $ours, string $message = ''): void
    {
        $this->assertSame(
            bin2hex((string)file_get_contents($theirs)),
            bin2hex((string)file_get_contents($ours)),
            $message,
        );
    }

    /**
     * Writes the same thing with each, and hands back where the two landed.
     *
     * @param list<array<int, mixed>> $structure
     * @param list<array<int, mixed>> $records
     * @return array{string, string}
     */
    private function build(array $structure, array $records): array
    {
        $theirs = $this->path('theirs-');
        $ours = $this->path('ours-');

        $theirDb = dbase_create($theirs, $structure);
        $this->assertNotFalse($theirDb);
        foreach ($records as $record) {
            dbase_add_record($theirDb, $record);
        }
        dbase_close($theirDb);

        $ourDb = $this->create($ours, $structure);
        foreach ($records as $record) {
            $ourDb->add_record($record);
        }
        $ourDb->close();

        return [$theirs, $ours];
    }
}
