# Changelog

## Notes

- GitHub: https://github.com/mr-manuel/LoxBerry-Plugin-miniserverbackup-ng
- Documentation: https://wiki.loxberry.de/plugins/miniserverbackup-ng/start

## 2026.10.17.2 (local error handling revision)

- Abort incomplete, invalid or failed file listings before changing cached files.
- Validate every download with transport status, HTTP status, expected size and error-page checks; retry into a fresh file once.
- Refresh file size once when a file changes during backup; persistent differences abort.
- Keep cached files unchanged on listing/download failures, and abort on failed cache moves.
- Verify new 7z archives before backup rotation; return a failure exit status for incomplete runs.
- Always archive validated server files even when their timestamps predate an older archived version.

## 2026.10.17.1 (local LoxBerry 4 build)

- Adapt installation metadata and Debian 13 dependencies to the local system.
- Protect plugin web endpoints and fix the backup authentication file path.
- Initialize disabled configuration and install the hidden access-control file.
- Resolve LoxBerry PHP libraries for CLI jobs without inherited environment.
- Fix manual backup scheduling and deletion propagation in 7z full backups.
- Quote 7z paths and avoid destructive config truncation before validation/locking.
- Remove shell commands from work-directory state and free-space handling.
- Disable automatic upstream updates for this local build.

## 2025.03.12

- Added: Option to select at which time the backup is executed, if the interval is at least one day
- Changed: Changed new name in a few more places
- Changed: E-Mail layout updated
- Changed: Hide backup compression field, if backup type is not 7-ZIP

## 2025.03.10

- Added: Option to select backup compression or also no compression (for deduplication)
- Changed: Exclude `/sys/tokens.xml` from backup, since the permission is denied with LoxConfig `15.5.x.x`
- Changed: Fixed settings template layout and saving of logging level change
- Changed: Plugin name from `miniserverbackup` to `miniserverbackup-ng` (next generation) to avoid conflicts with the old plugin

Forked from: https://github.com/Woersty/LoxBerry-Plugin-miniserverbackup
