#!/usr/bin/env bash
set -euo pipefail

THIS_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${THIS_SCRIPT_DIR}/.." && pwd)"
RUST_CRATE_DIR="${ROOT_DIR}/rust"
TARGET_RELEASE_DIR="${RUST_CRATE_DIR}/target/release"
PHP_BINDGEN_REPO="${PHP_BINDGEN_REPO:-https://github.com/huanghantao/uniffi-bindgen-php.git}"
PHP_BINDGEN_BRANCH="${PHP_BINDGEN_BRANCH:-main}"
PHP_BINDGEN_ROOT="${PHP_BINDGEN_ROOT:-${ROOT_DIR}/.tools/uniffi-bindgen-php/${PHP_BINDGEN_BRANCH}}"
PHP_BINDGEN_BIN="${PHP_BINDGEN_BIN:-${PHP_BINDGEN_ROOT}/bin/uniffi-bindgen-php}"
CARGO_TOOLCHAIN="${CARGO_TOOLCHAIN:-+1.90.0}"

cargo_cmd=(cargo)
if [[ -n "${CARGO_TOOLCHAIN}" ]]; then
  cargo_cmd+=("${CARGO_TOOLCHAIN}")
fi

bindgen_env=(env)
if [[ -n "${CARGO_TOOLCHAIN}" ]]; then
  bindgen_env+=("RUSTUP_TOOLCHAIN=${CARGO_TOOLCHAIN#+}")
fi

if [[ ! -x "${PHP_BINDGEN_BIN}" ]]; then
  echo "> Install uniffi-bindgen-php"
  "${cargo_cmd[@]}" install \
    --git "${PHP_BINDGEN_REPO}" \
    --branch "${PHP_BINDGEN_BRANCH}" \
    --locked \
    --root "${PHP_BINDGEN_ROOT}" \
    uniffi-bindgen-php
fi

echo "> Build Rust wrapper library"
(
  cd "${RUST_CRATE_DIR}"
  "${cargo_cmd[@]}" build --release
)

LIB_PATH="$(find "${TARGET_RELEASE_DIR}" -maxdepth 1 \( -name 'libloro_php.dylib' -o -name 'libloro_php.so' -o -name 'loro_php.dll' \) -print -quit)"
if [[ -z "${LIB_PATH}" ]]; then
  echo "Unable to find built loro-php cdylib under ${TARGET_RELEASE_DIR}" >&2
  exit 1
fi

mkdir -p "${ROOT_DIR}/gen-php" "${ROOT_DIR}/src"

echo "> Generate PHP bindings"
(
  cd "${RUST_CRATE_DIR}"
  "${bindgen_env[@]}" "${PHP_BINDGEN_BIN}" generate \
    --library "${LIB_PATH}" \
    --config "${ROOT_DIR}/uniffi.toml" \
    --out-dir "${ROOT_DIR}/gen-php" \
    --no-format
)

GENERATED_FILE="${ROOT_DIR}/gen-php/LoroFFI.php"
LC_ALL=C LANG=C perl -0pi -e "s/    private const LIBRARY = '[^']*';\n//" "${GENERATED_FILE}"
LC_ALL=C LANG=C perl -0pi -e 's/self::LIBRARY/loroPhpNativeLibraryPath()/g' "${GENERATED_FILE}"

cp "${GENERATED_FILE}" "${ROOT_DIR}/src/LoroFFI.php"
echo "> Wrote ${ROOT_DIR}/src/LoroFFI.php"

if [[ ! -x "${ROOT_DIR}/vendor/bin/php-cs-fixer" ]]; then
  echo "PHP CS Fixer is not installed. Run composer install before building PHP bindings." >&2
  exit 1
fi

echo "> Fix PHP coding style"
(
  cd "${ROOT_DIR}"
  composer cs-fix
)
