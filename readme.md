# mapik/dbase

A pure PHP implementation of the dBase functions, for where `ext-dbase` cannot be installed.

The extension is a PECL build with no package on most distributions, so an application that reads
or writes DBF files has to either compile it or go without. This stands in for it: require the
package and keep calling `dbase_open()`, `dbase_create()` and the rest, whichever of the two is
actually there.

```
composer require mapik/dbase
```

The global functions are defined **only when the extension is missing**, so nothing changes for an
installation that has it.

Two files, and the split is the point:

- `dbase.inc.php` is autoloaded on every request and holds nothing but the constants and the
  twelve function definitions.
- `src/DBase.php` holds the work, and the autoloader fetches it the first time one of those
  functions is called. Where the extension is installed that never happens at all.

The class can also be used directly as `Mapik\DBase\DBase`, which is what lets the two
implementations be held against each other in the tests. It carries a namespace where the original
did not: a global `DBase` is a name anything could claim, and two packages claiming it would fight
over which one the autoloader hands out. The functions stay global, because being callable under
their own names is the whole point of the package.

## What it matches

Byte for byte with the extension, proven by the test suite rather than by reading the manual:

- the header, the field descriptors, the record area and the `0x1A` end of file marker
- **how a value becomes characters**, which is the part that is easy to get wrong. The extension
  goes by the type of what it is handed and not by the field, so a float is written to the field's
  decimals and an integer is not: `0` and `0.0` in an `N,8,2` field come out as `       0` and
  `    0.00`. Strings pass through untouched.
- what comes back out: a number with decimals is a float and one without is an integer, text keeps
  the spaces it is stored with, and `deleted` sits after the fields rather than before them

`dbase_get_header_info()` returns the same six keys the extension does, `format` included.

## Requirements

PHP 8.1 or newer. No dependencies.

## Development

```
docker compose -f docker-compose.dev.yml up -d
docker compose -f docker-compose.dev.yml exec dev vendor/bin/phpunit
```

The development image installs the extension on purpose, because the parity tests are the ones
worth running and they skip themselves where there is nothing to compare against.

## Credit

A fork of [donfbecker/dbase](https://github.com/donfbecker/dbase), which is where the original
implementation and the MIT licence come from.
