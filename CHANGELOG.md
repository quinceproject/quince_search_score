# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versioning follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.2] - 2026-11-10

### Added

- Polish translation of every text the module shows, in the back office and in the shop.

## [0.1.1] - 2026-11-10

### Fixed

- The public tracking endpoint had no limit, so anyone could inflate the counters.
  It now counts at most 120 events per address in ten minutes, using the Quince
  Engine form guard (the address is kept only as a hash, for ten minutes).

## [0.1.0] - 2026-11-10

First public release.

### Added

- Tracking of search phrases on the search results page, counted per phrase.
- Tracking of clicks on products in the search results, adding one point to the
  product's score for each click.
- Back office screen with two tabs: products with their score, filtered by name,
  category and score range, with the score editable by hand; and phrases with how
  often they were searched. Both tabs sort and delete selected rows.
- Score used by Quince Search to break ties between equally relevant results and by
  Quince Product Section for a "most searched" product section, when those modules
  are installed.
- Declared dependency on Quince Engine.
