# Changelog

## Notes

- GitHub: https://github.com/mr-manuel/LoxBerry-Plugin-miniserverbackup-ng
- Documentation: https://wiki.loxberry.de/plugins/miniserverbackup-ng/start

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
