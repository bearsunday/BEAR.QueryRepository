## Changes

### Changed
- **PHP 8 Attributes Migration**: Removed `doctrine/annotations` and `doctrine/cache` dependencies, migrated to native PHP 8 attributes
- **Minimum PHP Version**: Updated requirement from PHP 8.1 to PHP 8.2
- **Development Tools**: Updated PHP_CodeSniffer to 4.0, Doctrine Coding Standard to 14.0, Slevomat Coding Standard to 8.24, PHPUnit to 11.5
- **Performance Improvements**: Set `ResourceStorage` to singleton scope, optimized readonly class declarations for PHP 8.2
- **Enhanced Redis**: Improved marshaller configuration with deflate compression support
- **Cross-Platform**: Normalized URI separators and path handling for Windows/macOS/Linux
- **Documentation**: Improved cache attribute and interceptor documentation with usage examples
- **Dependencies**: Updated Symfony Cache to ^7.3, ray/aop to ^2.16, ray/di to ^2.17.2, vimeo/psalm to 6.12

### Added
- **Migration Tools**: Added `rector-migrate.php` for automated annotation-to-attribute migration
- **Migration Guide**: Added `ANNOTATION_TO_ATTRIBUTE.md` with comprehensive migration instructions
- **PHP 8.4 & 8.5 Support**: Added PHP 8.4 and 8.5 support in CI workflow
- **Documentation**: Added `CLAUDE.md` with complete codebase architecture guide
- **Japanese Docs**: Added `README.ja.md`

### Removed
- `doctrine/annotations` (abandoned package)
- `doctrine/cache` (abandoned package)
- Sodium marshaller related code

## ⚠️ Migration Required

Applications using annotations must migrate to PHP 8 attributes:

```bash
# Preview changes
vendor/bin/rector process src --config=vendor/bear/query-repository/rector-migrate.php --dry-run

# Apply migration
vendor/bin/rector process src --config=vendor/bear/query-repository/rector-migrate.php
```

See `ANNOTATION_TO_ATTRIBUTE.md` for detailed migration guide.

## Why This Change?

The `doctrine/annotations` package has been officially **abandoned** by its maintainers and will no longer receive updates or security patches. This change:

- Eliminates security and compatibility risks from the abandoned package
- Adopts native PHP 8 attributes as the modern standard
- Improves performance (no runtime annotation parsing overhead)
- Provides migration tooling for users to update their applications

## Why Not a Major Version Update?

While this release removes doctrine/annotations, we consider it a minor version (1.13.0) rather than a major version (2.0.0) for the following reasons:

**No Public API Changes**
- All public interfaces, classes, and methods remain identical
- The annotation-to-attribute migration is purely a syntax change
- Existing code continues to work after applying the automated migration

**Automated Migration Available**
- We provide Rector configuration for one-command migration
- The migration process is safe, tested, and reversible
- Migration Guide includes comprehensive documentation and troubleshooting

**Semantic Versioning Perspective**
- Per semver, breaking changes are defined as incompatible API changes
- While dependencies changed, the API contract remains stable
- This follows the principle that if your code works on PHP 8.1+ with attributes, the upgrade path is clear and automated

**Version Strategy**
- Version 1.12.x continues to support annotations (requires doctrine/annotations)
- Version 1.13.0+ uses native PHP 8 attributes only
- Users can choose the appropriate version for their needs
