# Changelog

## [Unreleased]

- Added possibility to add YouTube- and Vimeo-videos as external files
- Added possibility to import Youtube-Channel-Videos as external files via YouTube API
- Added more hooks
- Introduced Services to platforms which host files (like Imgur or GoogleDrive)
- Optimized updating or installing log- and queue-tables during plugin update
- Updated dependencies
- Moved changelog from readme.txt in GitHub-repository
- Fixed potential error with import of YouTube videos
- Fixed output of hint it file is not available

## [2.0.2] - 2024-11-23

### Added

- Added option to use the external file date instead of the import date

### Fixed

- Fixed hook documentations

## [2.0.1] - 2024-11-11

### Changed

- Small optimizations on texts for better translations
- GPRD-hint is now also shown for old installations if it is not disabled

### Fixed

- Fixed update handler for WordPress 6.7
- Fixed setting of capabilities for Playground
- Fixed setting of capabilities on update

## [2.0.0] - 2024-11-10

### Added

- Revamped plugin
- Added queue for importing large amount of URLs
- Added support for import of directories with multiple files
- Added support for different tcp-protocols
- Added support for FTP-URLs
- Added support for SSH/SFTP-URLs
- Added support for file-URL (to import from local server)
- Added support for credentials for each tcp-protocol
- Added wrapper to support third party plugins or platforms, e.g. Imgur or Google Drive
- Added support for Rank Math
- Added warning about old PHP-versions
- Added option to switch external files to local hosting during uninstallation of the plugin
- Added WP CLI option to switch hosting of all files to local or external
- Added documentation for each possible option in GitHub
- Added link to settings in plugin list
- Added migration tool to switch the external files from Exmage to this one
- Added thumbnail support for proxied images
- Added settings for videos which now can also be proxied
- Added import and export for plugin settings
- Added a handful help texts for WordPress-own help system
- Added multiple new hooks
- Added statistic about used files.
- Added warning regarding the GPRD of the EU (could be disabled)

### Changed

- Compatible with WordPress 6.7
- External files which are not provided via SSL will be saved local if actual website is using SSL
- Extended WP CLI support with documentation, progressbar, states and arguments
- Replaced settings management with optimized objects
- Optimized proxy url handling
- Optimized build process for releases
- Optimized transients of this plugin
- Optimized log table with much more options
- Replaced dialog library with new one
- Renamed internal transient prefix for better compatibility with other plugins
- Move support for already supported plugins in new wrapper

### Fixed

- Fixed some typos
- Fixed error with import of multiple files via WP CLI

## [1.3.0] - 2024-08-25

### Added

- Added possibility to switch the hosting of images during local and extern on media edit page
- Added new column for marker of external files in media table

### Changed

- Compatibility with plugin Prevent Direct Access: hide options for external fields

### Fixed

- Fixed some typos
- Fixed wrong proxied URL after successful import of images

## [1.2.3] - 2024-08-17

### Changed

- Updated dependencies

## [1.2.2] - 2024-06-05

### Changed

- Updated compatibility-flag for WordPress 6.6
- Updated dependencies

### Fixed

- Fixed potential error on attachment pages

## [1.2.1] - 2024-05-05

### Added

- Added support for hook of plugin "Download List Block with Icons" for mark external files with rel-external

### Changed

- Updated compatibility-flag for WordPress 6.5.3
- Updated dependencies

## [1.2.0] - 2024-04-14

### Added

- New import dialog with progress and extended info about the import

### Changed

- Show proxy hint on file only if proxy is enabled
- Optimized style for box with infos about external files
- Updated compatibility-flag for WordPress 6.5.2
- Updated dependencies

## [1.1.2] - 2024-03-06

### Fixed

- Fixed possible error during check for current screen
- Fixed usage of URLs with ampersand on AJAX-request

## [1.1.1] - 2024-03-04

### Changed

- Proxy-slug will now also be changed with simple permalinks
- Updated compatibility-flag for WordPress 6.5
- Updated hook documentation

### Fixed

- Fixed support for spaces in URLs
- Fixed typo in examples in hook-documentation
- Fixed possible notice in transient-handler
- Fixed usage of proxy with simple permalinks

## [1.1.0] - 2024-02-04

### Added

- Added multiple hooks

### Changed

- Prevent usage of plugin with older PHP than required minimum
- Optimized content type detection
- Optimized attachment title handling with special chars
- Updated compatibility-flag for WordPress 6.4.3
- Updated dependencies

## [1.0.2] - 2024-01-14

### Added

- Added hook documentation
- Added hint for hook documentation in settings

### Changed

- Optimized handling of upload-form if nothing has been added there

### Removed

- Removed language files from release

# [1.0.1] - 2023-10-21

### Changed

- Updated compatibility-flag for WordPress 6.4
- Compatible with WordPress Coding Standards 3.0

### Fixed

- Fixed error in settings-save-process
- Fixed typo in translations

## [1.0.0] - 2023-09-04

### Added

- Initial release