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

## Usage

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Loro\Container;
use Loro\Export;
use Loro\LoroDoc;

$doc = new LoroDoc();
$text = $doc->getText(Container::idLike('text'));

$text->insert(0, 'hello');
$doc->commit();

$snapshot = $doc->export(Export::snapshot());
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
