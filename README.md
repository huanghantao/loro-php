# loro-php

Experimental PHP bindings for the [Loro](https://github.com/loro-dev/loro)
CRDT library, built on the official
[loro-ffi](https://github.com/loro-dev/loro-ffi) UniFFI component.

The package contains generated PHP FFI bindings plus a small hand-written
convenience layer under `src/`.

## Requirements

- PHP 8.1+
- `ext-ffi`
- `ffi.enable=1` at runtime
- A native `loro-php` dynamic library built for the current OS and CPU

Rust is only required when building the native library or regenerating the PHP
bindings.

## Install

```bash
composer require loro-dev/loro-php
```

This package does not commit native binaries to the repository. Provide the
dynamic library with `LORO_PHP_LIBRARY`:

```bash
export LORO_PHP_LIBRARY=/absolute/path/to/libloro_php.dylib
```

The expected library names are:

- macOS: `libloro_php.dylib`
- Linux: `libloro_php.so`
- Windows: `loro_php.dll`

If a release workflow downloads native artifacts into the package, place them
under `native/<platform>-<arch>/`, for example
`native/darwin-arm64/libloro_php.dylib`. The loader also checks `native/`
directly as a fallback.

## Classic Examples

Run examples with FFI enabled:

```bash
php -d ffi.enable=1 example.php
```

### Edit text, maps, and lists

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Loro\Container;
use Loro\Loro;
use Loro\LoroDoc;

$doc = new LoroDoc();

$text = $doc->getText('text');
$text->insert(0, 'Hello');
$text->insert(5, ', Loro');

$profile = $doc->getMap('profile');
Container::insertMapValue($profile, 'name', 'Ada');
Container::insertMapValue($profile, 'online', true);

$todos = $doc->getList('todos');
Container::pushListValue($todos, 'write docs');
Container::pushListValue($todos, 'ship release');

$doc->commit();

print_r(Loro::toJson($doc));
```

### Sync two documents

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Loro\Export;
use Loro\LoroDoc;
use Loro\VersionVector;

$alice = new LoroDoc();
$alice->setPeerId(1);
$aliceText = $alice->getText('text');
$aliceText->insert(0, 'Hello');
$alice->commit();

$bob = new LoroDoc();
$bob->setPeerId(2);
$bob->import($alice->export(Export::updates(new VersionVector())));

$bobText = $bob->getText('text');
$bobText->insert($bobText->lenUnicode(), ' from Bob');
$bob->commit();

$alice->import($bob->export(Export::updates($alice->oplogVv())));

echo $aliceText->slice(0, $aliceText->lenUnicode()); // Hello from Bob
```

### Rich text marks

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Loro\Container;
use Loro\Loro;
use Loro\LoroDoc;

$doc = new LoroDoc();
Loro::configureTextStyle($doc, ['bold' => 'after']);

$text = $doc->getText('text');
$text->insert(0, 'Hello world');
Container::markText($text, 0, 5, 'bold', true);

print_r(Loro::textDeltaToPhp($text->toDelta()));
```

### Save and restore a snapshot

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Loro\Export;
use Loro\Loro;
use Loro\LoroDoc;

$doc = new LoroDoc();
$doc->getText('text')->insert(0, 'snapshot me');
$doc->commit();

$snapshot = $doc->export(Export::snapshot());

$restored = new LoroDoc();
$restored->import($snapshot);

print_r(Loro::toJson($restored)); // ['text' => 'snapshot me']
```

### Share presence with Awareness

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Loro\Awareness;
use Loro\AwarenessState;

$alice = new Awareness(1, 30000);
AwarenessState::setLocalState($alice, [
    'name' => 'Alice',
    'cursor' => 5,
]);

$bob = new Awareness(2, 30000);
$bob->apply($alice->encodeAll());

print_r(AwarenessState::getState($bob, 1));
```

## Local Development

Install PHP dependencies:

```bash
composer install
```

Build the Rust wrapper library and regenerate `src/LoroFFI.php`:

```bash
./scripts/build_php_ffi.sh
```

The build script runs `composer cs-fix` after writing the generated PHP file.

The build script uses remote sources by default:

- `loro-ffi` from `https://github.com/loro-dev/loro-ffi.git`, tag `v1.13.0`
- `uniffi-bindgen-php` from
  `https://github.com/huanghantao/uniffi-bindgen-php.git`

No sibling checkout is required. The bindgen binary is installed into the
ignored `.tools/` directory.

Useful overrides:

```bash
CARGO_TOOLCHAIN=+1.90.0 ./scripts/build_php_ffi.sh
PHP_BINDGEN_BIN=/path/to/uniffi-bindgen-php ./scripts/build_php_ffi.sh
PHP_BINDGEN_REV=<commit-sha> ./scripts/build_php_ffi.sh
```

Run tests against the locally built native library:

```bash
LORO_PHP_LIBRARY="$(pwd)/rust/target/release/libloro_php.dylib" composer test
```

Format PHP code:

```bash
composer cs-fix
```

## Release Packaging

`native/` is intentionally ignored so compiled binaries do not have to live in
Git. Pushing a tag runs the release workflow, builds platform libraries, and
uploads `loro-php-native-<platform>-<arch>.tar.gz` assets to the GitHub Release.
Document `LORO_PHP_LIBRARY` for users who manage the binary themselves, or add
an installer step that downloads the matching GitHub Release artifact into
`native/<platform>-<arch>/`.

Packagist installs the Composer source archive; it does not automatically
include GitHub Release artifacts.
