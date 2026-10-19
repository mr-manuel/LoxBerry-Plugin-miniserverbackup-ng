#!/bin/sh
set -eu
base=${5:?Missing LoxBerry base directory}
folder=${3:?Missing plugin folder}
version=${4:?Missing plugin version}
package_dir=${6:-.}
html="$base/webfrontend/html/plugins/$folder"
config="$base/config/plugins/$folder"
# The core installer copies html/* and skips top-level dotfiles.
# Install the access control explicitly, including on upgrades.
install -m 0644 -- "$package_dir/webfrontend/html/.htaccess" "$html/.htaccess"
mkdir -p -- "$html/backups" "$base/data/plugins/$folder/backup_storage" "$config"
if [ ! -s "$config/miniserverbackup-ng.cfg" ]; then
    umask 077
    cat > "$config/miniserverbackup-ng.cfg" <<EOF
[MINISERVERBACKUP]
VERSION="$version"
MSBACKUP_USE="off"
MSBACKUP_USE_NOTIFY="off"
MSBACKUP_USE_EMAILS="off"
RANDOM_SLEEP="5"
EOF
fi
echo "<OK> Web access protected; configuration initialized with backups disabled."
