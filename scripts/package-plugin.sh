#!/bin/sh
set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
plugin_dir=$(dirname "$script_dir")
parent_dir=$(dirname "$plugin_dir")
version=$(awk '/^[[:space:]]*\* Version:/ { print $3; exit }' "$plugin_dir/eventflow.php")

if [ -z "$version" ]; then
	printf '%s\n' 'Could not read the plugin version.' >&2
	exit 1
fi

if [ ! -f "$plugin_dir/assets/build/index.js" ] || [ ! -f "$plugin_dir/assets/build/event-list.js" ]; then
	cd "$plugin_dir"
	npm run build
fi

mkdir -p "$plugin_dir/dist"
archive="$plugin_dir/dist/eventflow-$version.zip"
rm -f "$archive"
cd "$parent_dir"
zip -qr "$archive" "$(basename "$plugin_dir")" \
	-x "$(basename "$plugin_dir")/node_modules/*" \
	"$(basename "$plugin_dir")/dist/*" \
	"$(basename "$plugin_dir")/.git/*" \
	"$(basename "$plugin_dir")/.DS_Store" \
	"$(basename "$plugin_dir")/.gitignore" \
	"$(basename "$plugin_dir")/AGENTS.md" \
	"$(basename "$plugin_dir")/README.md" \
	"$(basename "$plugin_dir")/docs/*" \
	"$(basename "$plugin_dir")/scripts/*" \
	"$(basename "$plugin_dir")/package.json" \
	"$(basename "$plugin_dir")/package-lock.json"

printf 'Created %s\n' "$archive"
