<?php

declare(strict_types=1);

namespace Mapik\DBase\Tests;

use Mapik\DBase\DBase;
use PHPUnit\Framework\TestCase;

/**
 * What the implementation does on its own, whether or not the extension is installed.
 *
 * The class is asked rather than the dbase_*() functions, because those step aside where the
 * extension is loaded and a test of them would then be a test of something else.
 */
class DBaseTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            @unlink($path);
        }

        $this->written = [];
        parent::tearDown();
    }

    public function testAFileIsWrittenAndReadBack(): void
    {
        $path = $this->path();

        $db = DBase::create($path, [['NAME', 'C', 10], ['AMOUNT', 'N', 8, 2]]);
        $this->assertNotFalse($db);
        $this->assertTrue($db->add_record(['Nested', 19.9]));
        $this->assertTrue($db->add_record(['Street', 0]));
        $db->close();

        $db = DBase::open($path, DBASE_RDONLY);
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

        $db = DBase::create($path, [['A', 'N', 8, 2]]);
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

        $db = DBase::create($path, [['A', 'C', 5]]);
        $db->add_record(['ab']);
        $db->add_record(['abcdefg']);
        $db->close();

        $db = DBase::open($path, DBASE_RDONLY);
        $this->assertSame('ab   ', $db->get_record_with_names(1)['A']);
        $this->assertSame('abcde', $db->get_record_with_names(2)['A']);
        $db->close();
    }

    public function testEveryStateOfTheFileEndsWithTheMarker(): void
    {
        $path = $this->path();

        $db = DBase::create($path, [['A', 'C', 4]]);
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

        $db = DBase::create($path, [['A', 'C', 4]]);
        $db->add_record(['one']);
        $db->add_record(['two']);
        $db->add_record(['ten']);
        $this->assertTrue($db->delete_record(2));
        $this->assertTrue($db->pack());
        $db->close();

        $db = DBase::open($path, DBASE_RDONLY);
        $this->assertSame(2, $db->numrecords());
        $this->assertSame('one ', $db->get_record_with_names(1)['A']);
        $this->assertSame('ten ', $db->get_record_with_names(2)['A']);
        $db->close();
    }

    public function testARecordIsReplacedWhereItStands(): void
    {
        $path = $this->path();

        $db = DBase::create($path, [['A', 'C', 4]]);
        $db->add_record(['one']);
        $db->add_record(['two']);
        $this->assertTrue($db->replace_record(['six'], 1));
        $db->close();

        $db = DBase::open($path, DBASE_RDONLY);
        $this->assertSame('six ', $db->get_record_with_names(1)['A']);
        $this->assertSame('two ', $db->get_record_with_names(2)['A']);
        $db->close();
    }

    public function testTheHeaderSaysWhatEachFieldIs(): void
    {
        $path = $this->path();

        $db = DBase::create($path, [['S', 'C', 5], ['N', 'N', 6, 2]]);
        $db->close();

        $db = DBase::open($path, DBASE_RDONLY);
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

        $db = DBase::create($path, [['A', 'C', 4]]);
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

        $db = DBase::create($path, [['A', 'C', 4]]);
        $db->add_record(['one']);
        $db->close();

        $db = DBase::open($path, DBASE_WRONLY);
        $this->assertNotFalse($db);
        $this->assertSame(1, $db->numrecords());
        $db->close();
    }

    private function path(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('dbase-', true) . '.dbf';
        $this->written[] = $path;

        return $path;
    }

    private function lastByte(string $path): string
    {
        return substr((string)file_get_contents($path), -1);
    }
}
