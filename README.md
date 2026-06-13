# loro-php

PHP bindings for [Loro](https://github.com/loro-dev/loro), built with UniFFI
and PHP FFI.

## Requirements

- PHP 8.1+
- `ext-ffi`
- `ffi.enable=1`

## Install

```bash
composer require huanghantao/loro-php
```

Composer will ask whether `huanghantao/loro-php` may run as a plugin. Allow it
to download the native library for your platform.

For CI:

```bash
composer config allow-plugins.huanghantao/loro-php true
composer require huanghantao/loro-php
```

To use your own native library instead:

```bash
export LORO_PHP_LIBRARY=/absolute/path/to/libloro_php.dylib
```

## Usage

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Loro\LoroDoc;

$doc = new LoroDoc();
$text = $doc->getText('text');

$text->insert(0, 'Hello, Loro');
$doc->commit();

echo $text->slice(0, $text->lenUnicode());
```

Run PHP with FFI enabled:

```bash
php -d ffi.enable=1 example.php
```

## Development

```bash
composer install
./scripts/build_php_ffi.sh
LORO_PHP_LIBRARY="$(pwd)/rust/target/release/libloro_php.dylib" composer test
composer cs-fix
```
