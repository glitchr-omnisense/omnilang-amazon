# Changelog

## 1.x (unreleased)

- 2026-11-10: the provider written, on omnisense/omnilang and omnisense/contracts, without the AWS
  SDK: the JSON protocol (awsJson 1.1, target prefix AWSShineFrontendService_20170701) and
  Signature Version 4 (SignatureV4, checked against AWS's get-vanilla and post-vanilla vectors).
  TranslateText (auto, formality, profanity, brevity, a terminology), ListLanguages (pages),
  ImportTerminology (CSV), ListTerminologies, GetTerminology (and its presigned file),
  DeleteTerminology, TranslateDocument. Credentials by reference. policy.json: the terminology
  rates, data use and its opt-out. Tests on MockHttp over answers written from the API reference,
  each asserting the target, the body and its signature; PolicyTest, BareTest; PHPStan at max,
  baseline empty.
