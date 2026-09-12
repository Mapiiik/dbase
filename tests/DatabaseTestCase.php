<?php

declare(strict_types=1);

namespace Mapik\DBase\Tests;

use Mapik\DBase\DBase;
use PHPUnit\Framework\TestCase;

/**
 * The bookkeeping both test classes need: somewhere to write, and a way to open what was written.
 *
 * create() and open() answer with false where they cannot do what was asked, so every test would
 * otherwise either check for it or fall over on a null call. Asserting here says so once, and the
 * tests that are about being refused ask the class directly.
 */
abstract class DatabaseTestCase extends TestCase
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

    /**
     * @param list<array<int, mixed>> $structure
     */
    protected function create(string $path, array $structure): DBase
    {
        $db = DBase::create($path, $structure);
        $this->assertNotFalse($db, 'the database could not be created');

        return $db;
    }

    protected function open(string $path, int $mode = DBASE_RDONLY): DBase
    {
        $db = DBase::open($path, $mode);
        $this->assertNotFalse($db, 'the database could not be opened');

        return $db;
    }

    /**
     * One record, by name, with the miss already ruled out.
     *
     * @return array<string, mixed>
     */
    protected function record(DBase $db, int $number): array
    {
        $record = $db->get_record_with_names($number);
        $this->assertNotFalse($record, 'there is no record ' . $number);

        return $record;
    }

    /**
     * Somewhere to write that is cleared away afterwards.
     */
    protected function path(string $prefix = 'dbase-'): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid($prefix, true) . '.dbf';
        $this->written[] = $path;

        return $path;
    }
}
