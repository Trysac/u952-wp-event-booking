# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Added atomic event booking core (`Event`, `Attendee`, `BookingService`, `PdoStore`, `InMemoryStore`, `RecordingMailer`, `Messages`, and `Cli`) with FIFO waitlist promotion, idempotency keys, header-injection protection, and 20-process concurrency verification.
- Initial project structure from the factory utility template.
