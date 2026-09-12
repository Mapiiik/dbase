<?php
declare(strict_types=1);

namespace Mapik\DBase\Tests;

use DBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The same database written both ways, held against each other byte for byte.
 *
 * This is the only test that can say whether the stand-in is one, so where the extension is
 * missing it skips rather than passing quietly and saying nothing.
 */
class ExtensionParityTest extends TestCase
{
	/**
	 * @var list<string>
	 */
	private array $written = [];

	protected function setUp(): void
	{
		parent::setUp();

		if (!extension_loaded('dbase')) {
			$this->markTestSkipped('There is nothing to compare against without ext-dbase.');
		}
	}

	protected function tearDown(): void
	{
		foreach ($this->written as $path) {
			@unlink($path);
		}

		$this->written = [];
		parent::tearDown();
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

		$this->assertSame(
			bin2hex((string)file_get_contents($theirs)),
			bin2hex((string)file_get_contents($ours)),
		);
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
		$ourDb = DBase::open($ours, DBASE_RDONLY);

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
		$structure = [['A', 'C', 4]];
		$records = [['one'], ['two'], ['ten']];

		[$theirs, $ours] = $this->build($structure, $records);

		$theirDb = dbase_open($theirs, DBASE_RDWR);
		$ourDb = DBase::open($ours, DBASE_RDWR);

		dbase_delete_record($theirDb, 2);
		$ourDb->delete_record(2);
		$this->assertSame(
			bin2hex((string)file_get_contents($theirs)),
			bin2hex((string)file_get_contents($ours)),
			'after marking one as gone',
		);

		dbase_pack($theirDb);
		$ourDb->pack();
		dbase_close($theirDb);
		$ourDb->close();

		$this->assertSame(
			bin2hex((string)file_get_contents($theirs)),
			bin2hex((string)file_get_contents($ours)),
			'after packing',
		);
	}

	public function testReplacingARecordLeavesTheSameFile(): void
	{
		$structure = [['A', 'C', 4], ['B', 'N', 6, 2]];
		$records = [['one', 1.0], ['two', 2.5]];

		[$theirs, $ours] = $this->build($structure, $records);

		$theirDb = dbase_open($theirs, DBASE_RDWR);
		$ourDb = DBase::open($ours, DBASE_RDWR);

		dbase_replace_record($theirDb, ['six', 6.75], 1);
		$ourDb->replace_record(['six', 6.75], 1);

		dbase_close($theirDb);
		$ourDb->close();

		$this->assertSame(
			bin2hex((string)file_get_contents($theirs)),
			bin2hex((string)file_get_contents($ours)),
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
		$theirs = $this->path();
		$ours = $this->path();

		$theirDb = dbase_create($theirs, $structure);
		foreach ($records as $record) {
			dbase_add_record($theirDb, $record);
		}
		dbase_close($theirDb);

		$ourDb = DBase::create($ours, $structure);
		foreach ($records as $record) {
			$ourDb->add_record($record);
		}
		$ourDb->close();

		return [$theirs, $ours];
	}

	private function path(): string
	{
		$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('parity-', true) . '.dbf';
		$this->written[] = $path;

		return $path;
	}
}
