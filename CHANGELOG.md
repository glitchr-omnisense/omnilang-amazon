# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable version. It requires `omnisense/contracts` `^1.0`, `omnisense/omnilang` `^1.0` with no stability flag, so `composer require omnilang/amazon` installs it in a project with Composer's default minimum-stability; the 1.x series follows semantic versioning. A tag `vX.Y.Z` is released by `.github/workflows/release.yml` once the package's CI passes on it.

- 2026-11-10: the provider written, on omnisense/omnilang and omnisense/contracts, without the AWS
  SDK: the JSON protocol (awsJson 1.1, target prefix AWSShineFrontendService_20170701) and
  Signature Version 4 (SignatureV4, checked against AWS's get-vanilla and post-vanilla vectors).
  TranslateText (auto, formality, profanity, brevity, a terminology), ListLanguages (pages),
  ImportTerminology (CSV), ListTerminologies, GetTerminology (and its presigned file),
  DeleteTerminology, TranslateDocument. Credentials by reference. policy.json: the terminology
  rates, data use and its opt-out. Tests on MockHttp over answers written from the API reference,
  each asserting the target, the body and its signature; PolicyTest, BareTest; PHPStan at max,
  baseline empty.
