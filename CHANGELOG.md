# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

## 2.0.2 - 2025-03-20

### Fixed

- Prevent error when deleting a non-existent folder.

## 2.0.1 - 2025-01-29

### Fixed

- Allow moving asset to root folder in dynamic folder mode.

### Changed

- Bump cloudinary_php version to 3.1.0

## 2.0.0 - 2024-12-18

### Added

- Implement `PublicUrlGenerator`.

### Fixed

- Throw correct error in copy method.
- Handle issues with eventual consistency in the Search API.

### Removed

- **Breaking:** Remove manual path prefixing.

### Changed

- **Breaking:** Default to dynamic folder mode.
- Improve code.
- Fix code style.
- Update README.

## 1.0.4 - 2024-06-01

### Fixed

- Correct resource types.

## 1.0.3 - 2024-05-31

### Fixed

- Classify PDFs as images.

## 1.0.2 - 2024-05-23

### Fixed

- Ignore backup placeholders.

## 1.0.1 - 2024-04-08

### Fixed

- Allow altering an asset's folder in dynamic folder mode.

## 1.0.0 - 2024-01-26

- Initial release.