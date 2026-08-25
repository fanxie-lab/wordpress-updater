#!/usr/bin/env bash
#
# Build a distributable ZIP for a theme or plugin package.
#
#   bin/build.sh --source=DIR --slug=NAME --version=1.2.3 \
#                 --main-file=my-plugin.php --key-file=src/Update/PublicKey.php \
#                 --out=dist
#
# What it does, in order:
#
#   1. refuses a version that is not semver, and refuses to build at all while
#      no update signing key is compiled into the package (see --allow-unkeyed);
#   2. copies the source directory into $OUT/stage/$SLUG, so the version is
#      never stamped into the working tree — a build must not dirty the source;
#   3. stamps the version into the main file's header, the composer.json and
#      readme.txt's Stable tag, where each exists;
#   4. runs `composer install --no-dev` *inside the staged package*, because
#      each package carries its own autoloader and the zip is the only thing
#      that reaches the site;
#   5. deletes development files;
#   6. zips it with exactly one top-level directory, named for the slug.
#
# Production asset builds (npm run build, etc.) are the caller's job and must
# run against --source before this script is invoked.
#
# The ZIP is what WordPress unpacks into wp-content. If its internal directory
# name is wrong the update installs beside the live copy instead of replacing
# it, so step 6 is a PHP ZipArchive call in bin/release.php rather than a
# `zip -r` whose result depends on the current working directory.
#
# shellcheck shell=bash

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly REPO_ROOT

SOURCE_DIR=""
SLUG=""
VERSION=""
MAIN_FILE=""
KEY_FILE=""
OUT_DIR="${REPO_ROOT}/dist"
ALLOW_UNKEYED=0

die() {
	printf 'fx-build: %s\n' "$1" >&2
	exit 1
}

note() {
	printf '  %s\n' "$1"
}

for arg in "$@"; do
	case "$arg" in
		--source=*)       SOURCE_DIR="${arg#*=}" ;;
		--slug=*)         SLUG="${arg#*=}" ;;
		--version=*)      VERSION="${arg#*=}" ;;
		--main-file=*)    MAIN_FILE="${arg#*=}" ;;
		--key-file=*)     KEY_FILE="${arg#*=}" ;;
		--out=*)          OUT_DIR="${arg#*=}" ;;
		--allow-unkeyed)  ALLOW_UNKEYED=1 ;;
		*) die "unknown argument: ${arg}" ;;
	esac
done

# --- 1. Validate -------------------------------------------------------------

VERSION="${VERSION#v}"

[[ -n "$SOURCE_DIR" ]] || die "missing --source=DIR"
[[ -n "$SLUG" ]] || die "missing --slug=NAME"
[[ -n "$VERSION" ]] || die "missing --version=X.Y.Z"
[[ -n "$MAIN_FILE" ]] || die "missing --main-file=REL"
[[ -n "$KEY_FILE" ]] || die "missing --key-file=PATH"

if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z.-]+)?$ ]]; then
	die "version '${VERSION}' is not semver"
fi

[[ -d "$SOURCE_DIR" ]] || die "source directory not found: ${SOURCE_DIR}"

HEADER_FILE="$MAIN_FILE"

# A build with no trust anchor produces a ZIP no site will ever be told about,
# because the manifest that points at it cannot be verified. Better to stop here
# than to publish a release that silently never installs.
KEY_LINE="$(grep -o "public const COMPILED = '[^']*';" "$KEY_FILE" || true)"

if [[ "$KEY_LINE" == "public const COMPILED = '';" ]]; then
	if [[ "$ALLOW_UNKEYED" -eq 1 ]]; then
		printf 'fx-build: WARNING — no update signing key is compiled in.\n' >&2
		printf 'fx-build: This ZIP is for local inspection only. Do not publish it.\n' >&2
	else
		die "no update signing key compiled in. Run: php bin/release.php keygen --write-to=${KEY_FILE}"
	fi
fi

printf 'Building %s %s\n' "$SLUG" "$VERSION"

# --- 2. Stage ----------------------------------------------------------------

STAGE_ROOT="${OUT_DIR}/stage"
STAGE_DIR="${STAGE_ROOT}/${SLUG}"

rm -rf "$STAGE_DIR"
mkdir -p "$STAGE_DIR"

# -a preserves nothing that matters here but keeps symlink handling sane; the
# trailing /. copies the contents rather than the directory itself.
cp -R "${SOURCE_DIR}/." "$STAGE_DIR"

# Anything the working tree happened to be carrying goes now, before we stamp.
rm -rf "${STAGE_DIR}/vendor" "${STAGE_DIR}/node_modules"

note "staged into ${STAGE_DIR}"

# --- 3. Stamp the version ----------------------------------------------------

stamp() {
	# stamp <file> <extended-regex> — portable in-place edit. `sed -i` differs
	# between GNU and BSD; writing to a temporary file does not.
	local file="$1" expression="$2"
	[[ -f "$file" ]] || return 0
	sed -E "$expression" "$file" > "${file}.stamped"
	mv "${file}.stamped" "$file"
}

stamp "${STAGE_DIR}/${MAIN_FILE}" "s/^Version:[[:space:]]*.*$/Version: ${VERSION}/"
stamp "${STAGE_DIR}/${MAIN_FILE}" "s/^([[:space:]]*\*[[:space:]]*Version:[[:space:]]*).*$/\1${VERSION}/"

stamp "${STAGE_DIR}/composer.json" "s/(\"version\"[[:space:]]*:[[:space:]]*\")[^\"]*(\")/\1${VERSION}\2/"
stamp "${STAGE_DIR}/readme.txt" "s/^Stable tag:[[:space:]]*.*$/Stable tag: ${VERSION}/"

if ! grep -qE "(^Version: |Version:[[:space:]]+)${VERSION}\$" "${STAGE_DIR}/${HEADER_FILE}"; then
	die "version stamp did not take in ${HEADER_FILE}"
fi

note "stamped ${VERSION} into ${HEADER_FILE}"

# --- 4. Composer, inside the package -----------------------------------------

if [[ -f "${STAGE_DIR}/composer.json" ]]; then
	( cd "$STAGE_DIR" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet )
	[[ -f "${STAGE_DIR}/vendor/autoload.php" ]] || die "composer produced no autoloader in ${STAGE_DIR}"
	note "composer install --no-dev produced vendor/autoload.php"
else
	note "no composer.json in this package; skipping composer"
fi

# --- 5. Prune development files ----------------------------------------------

# Everything here is either a developer's tooling or a build input. None of it
# is needed to run the package, and every file that ships is a file that has to
# be reviewed one day. `*.src.*` is the convention for the second kind: an
# asset master that something else is generated from and that nothing links to.
while IFS= read -r -d '' path; do
	rm -rf "$path"
done < <(find "$STAGE_DIR" \( \
	-name '.git*' -o \
	-name '.DS_Store' -o \
	-name '*.src.*' -o \
	-name 'node_modules' -o \
	-name 'composer.lock' -o \
	-name 'package.json' -o \
	-name 'package-lock.json' -o \
	-name '*.dist' -o \
	-name '*.map' -o \
	-name 'phpunit.xml' -o \
	-name '.editorconfig' -o \
	-name '.eslintrc*' -o \
	-name '.stylelintrc*' -o \
	-name 'webpack.config.js' \
	\) -print0)

note "pruned development files"

# --- 6. Zip ------------------------------------------------------------------

mkdir -p "$OUT_DIR"
ZIP_PATH="${OUT_DIR}/${SLUG}-${VERSION}.zip"

php "$(dirname "${BASH_SOURCE[0]}")/release.php" zip \
	--source="$STAGE_DIR" \
	--slug="$SLUG" \
	--out="$ZIP_PATH"

if command -v shasum > /dev/null 2>&1; then
	note "sha256 $(shasum -a 256 "$ZIP_PATH" | cut -d' ' -f1)"
fi

printf '%s\n' "$ZIP_PATH"
