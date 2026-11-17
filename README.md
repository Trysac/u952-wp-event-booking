# wp-event-booking

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![CI](https://github.com/Trysac/u952-wp-event-booking/actions/workflows/ci.yml/badge.svg)](https://github.com/Trysac/u952-wp-event-booking/actions/workflows/ci.yml)

Event booking core with seat capacity, FIFO waitlists, idempotent requests and atomic conditional updates that never oversell under concurrent bookings, ready for WordPress

## Problem

Describe in two or three sentences the concrete problem this utility solves, who hits it, and what
they do today without it. Replace this paragraph when the first real feature lands.

## Install

```sh
composer require trysac/u952-wp-event-booking
```

Until it is published on Packagist, add this repository as a VCS repository in your
`composer.json` and require a release tag. Requires PHP 8.4 or newer. No dependencies outside the
PHP standard library.

## Usage

```php
<?php

declare(strict_types=1);

use Trysac\WpEventBooking\Text;

// normalize() trims surrounding whitespace and lower-cases the text.
$name = Text::normalize('  Hello World '); // 'hello world'

// Blank input is rejected explicitly instead of returning an empty string.
Text::normalize('   '); // throws BlankInputException ('input is blank')
```

## Documentation

- [Architecture](docs/ARCHITECTURE.md) and [decisions](docs/decisions/)
- [Testing](docs/TESTING.md) and [security](docs/SECURITY.md)
- [Project record](docs/PROJECT.md) and [stack](docs/STACK.md)
- [Changelog](CHANGELOG.md) and [contributing guide](CONTRIBUTING.md)

## License

[MIT](LICENSE) (c) 2026 Miguel Gomez
