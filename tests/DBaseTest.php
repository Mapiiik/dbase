<?php

declare(strict_types=1);

namespace Mapik\DBase\Tests;

use Mapik\DBase\DBase;

/**
 * What the implementation does on its own, whether or not the extension is installed.
 *
 * The class is asked rather than the dbase_*() functions, because those step aside where the
 * extension is loaded and a test of them would then be a test of something else.
 */
class DBaseTest extends DatabaseTestCase
{
    public function testAFileIsWrittenAndReadBack(): void
    {
        $path = $this->path();

        $db = $this->create($path, [['NAME', 'C', 10], ['AMOUNT', 'N', 8, 2]]);
        $this->assertTrue($db->add_record(['Nested', 19.9]));
        $this->assertTrue($db->add_record(['Street', 0]));
        $db->close();

        $db = $this->open($path);
        $this->assertSame(2, $db->numfields());
        $this->assertSame(2, $db->numrecords());
        $this->assertSame(
            ['NAME' => 'Nested    ', 'AMOUNT' => 19.9, 'deleted' => 0],
            $db->get_record_with_names(1),
        );
        $db->close();
    }

    /**
     * A number is written to the decimals the field was given, and an integer is not dressed up
     * as one. That is the extension's rule and the one the file has to follow.
     */
    public function testNumbersKeepTheDecimalsTheFieldWasGiven(): void
    {
        $path = $this->path();

        $db = $this->create($path, [['A', 'N', 8, 2]]);
        $db->add_record([-7.5]);
        $db->add_record([0]);
        $db->close();

        $raw = (string)file_get_contents($path);
        $this->assertStringContainsString('   -7.50', $raw);
        $this->assertStringContainsString('       0', $raw);
    }

    /**
     * Text sits at the left of its field and comes back with the spaces still on it, because
     * that is what is stored and the extension does not tidy it away.
     */
    public function testTextComesBackTheWidthItWasStored(): void
    {
        $path = $this->path();

        $db = $this->create($path, [['A', 'C', 5]]);
        $db->add_record(['ab']);
        $db->add_record(['abcdefg']);
        $db->close();

        $db = $this->open($path);
        $this->assertSame('ab   ', $this->record($db, 1)['A']);
        $this->assertSame('abcde', $this->record($db, 2)['A']);
        $db->close();
    }

    public function testEveryStateOfTheFileEndsWithTheMarker(): void
    {
        $path = $this->path();

        $db = $this->create($path, [['A', 'C', 4]]);
        $this->assertSame("\x1A", $this->lastByte($path), 'a database with nothing in it');

        $db->add_record(['one']);
        $this->assertSame("\x1A", $this->lastByte($path), 'after a record');

        $db->add_record(['two']);
        $db->delete_record(1);
        $db->pack();
        $db->close();

        $this->assertSame("\x1A", $this->lastByte($path), 'after packing');
    }

    public function testPackingDropsWhatWasDeletedAndKeepsTheRest(): void
    {
        $path = $this->path();

        $db = $this->create($path, [['A', 'C', 4]]);
        $db->add_record(['one']);
        $db->add_record(['two']);
        $db->add_record(['ten']);
        $this->assertTrue($db->delete_record(2));
        $this->assertTrue($db->pack());
        $db->close();

        $db = $this->open($path);
        $this->assertSame(2, $db->numrecords());
        $this->assertSame('one ', $this->record($db, 1)['A']);
        $this->assertSame('ten ', $this->record($db, 2)['A']);
        $db->close();
    }

    public function testARecordIsReplacedWhereItStands(): void
    {
        $path = $this->path();

        $db = $this->create($path, [['A', 'C', 4]]);
        $db->add_record(['one']);
        $db->add_record(['two']);
        $this->assertTrue($db->replace_record(['six'], 1));
        $db->close();

        $db = $this->open($path);
        $this->assertSame('six ', $this->record($db, 1)['A']);
        $this->assertSame('two ', $this->record($db, 2)['A']);
        $db->close();
    }

    public function testTheHeaderSaysWhatEachFieldIs(): void
    {
        $path = $this->path();

        $db = $this->create($path, [['S', 'C', 5], ['N', 'N', 6, 2]]);
        $db->close();

        $db = $this->open($path);
        $this->assertSame(
            [
                [
                    'name' => 'S', 'type' => 'character', 'length' => 5,
                    'precision' => 0, 'format' => '%-5s', 'offset' => 1,
                ],
                [
                    'name' => 'N', 'type' => 'number', 'length' => 6,
                    'precision' => 2, 'format' => '%6s', 'offset' => 6,
                ],
            ],
            $db->get_header_info(),
        );
        $db->close();
    }

    public function testWhatCannotBeDoneIsRefusedRatherThanGuessedAt(): void
    {
        $path = $this->path();

        $db = $this->create($path, [['A', 'C', 4]]);
        $this->assertFalse($db->add_record(['one', 'two']), 'more values than fields');
        $this->assertFalse($db->get_record(1), 'a record that is not there');
        $this->assertFalse($db->delete_record(0), 'records are counted from one');
        $db->close();

        $this->assertFalse(DBase::create($path, [['A', 'C', 4]]), 'a file that already exists');
        $this->assertFalse(DBase::open($path . '.missing', DBASE_RDONLY), 'a file that does not');
    }

    /**
     * Opening for writing leaves what is there alone. Truncating it would be a strange reading of
     * the request, and it is not what the extension does.
     */
    public function testOpeningForWritingKeepsWhatIsAlreadyThere(): void
    {
        $path = $this->path();

        $db = $this->create($path, [['A', 'C', 4]]);
        $db->add_record(['one']);
        $db->close();

        $db = $this->open($path, DBASE_WRONLY);
        $this->assertSame(1, $db->numrecords());
        $db->close();
    }

    /**
     * The functions leave the handle untyped so that an application written against the extension
     * passes analysis either way. What actually arrives is still looked at.
     */
    public function testAHandleThatIsNotOneIsRefused(): void
    {
        $path = $this->path();

        $db = $this->create($path, [['A', 'C', 4]]);
        $this->assertSame($db, DBase::given($db, 'dbase_close'));
        $db->close();

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('dbase_close(): Argument #1 ($dbase_identifier) must be of type');

        DBase::given(fopen($path, 'r'), 'dbase_close');
    }

    private function lastByte(string $path): string
    {
        return substr((string)file_get_contents($path), -1);
    }
}
