---
title: Amazon Translate
order: 0
---

# omnilang/amazon

Read on 2026-11-10: the Amazon Translate API reference (TranslateText, TranslateDocument, Document,
TranslationSettings, ListLanguages, ImportTerminology, TerminologyData, TerminologyProperties,
ListTerminologies, GetTerminology, DeleteTerminology, common errors), the developer guide's quotas,
the General Reference's endpoints, the FAQ and the pricing page; botocore's model of the service
(`translate/2017-07-01/service-2.json`: `targetPrefix` AWSShineFrontendService_20170701,
`jsonVersion` 1.1, `signingName` translate, `json` among its protocols).

## Options

| Option | Default | |
|---|---|---|
| `region` | required | `eu-west-1`... |
| `access_key_id`, `secret_access_key` | required | by reference; the secret never travels, only its signature |
| `session_token` | none | sent as `X-Amz-Security-Token`, signed |
| `base_uri` | `https://translate.<region>.amazonaws.com` | a FIPS or `.api.aws` host |
| `profanity` | false | `Settings.Profanity: MASK` |
| `brevity` | false | `Settings.Brevity: ON` (TranslateText only) |
| `timeout` | 60 s | |

Every call: `POST /`, `Content-Type: application/x-amz-json-1.1`,
`X-Amz-Target: AWSShineFrontendService_20170701.<Operation>`, `X-Amz-Date`, and
`Authorization: AWS4-HMAC-SHA256 Credential=<key id>/<day>/<region>/translate/aws4_request,
SignedHeaders=content-type;host;x-amz-date;x-amz-target, Signature=…`.

## Calls

| Method | Operation | Read |
|---|---|---|
| `translate()` | `TranslateText` per text: `Text`, `SourceLanguageCode` (`auto` when none), `TargetLanguageCode`, `TerminologyNames`, `Settings` | `TranslatedText`, `SourceLanguageCode` (the detected one), `AppliedTerminologies` |
| `languages()` | `ListLanguages`: `DisplayLanguageCode: en`, `MaxResults: 500`, `NextToken` | `Languages[]`: `LanguageCode` (`auto` left out), `LanguageName` |
| `createGlossary()` | `ImportTerminology`: `Name` (letters, digits, hyphens, single underscores), `MergeStrategy: OVERWRITE`, `TerminologyData` (CSV in base64, a first row of language codes, `Directionality: UNI`) | `TerminologyProperties` |
| `glossaries()` | `ListTerminologies` (pages) | `TerminologyPropertiesList[]`: `Name`, `Description`, `SourceLanguageCode`, `TargetLanguageCodes`, `TermCount`, `CreatedAt` (epoch seconds) |
| `glossary()` | `GetTerminology` (`ResourceNotFoundException`: null) | `TerminologyProperties` |
| `glossaryEntries()` | `GetTerminology` with `TerminologyDataFormat: CSV`, then `GET` its presigned `TerminologyDataLocation.Location` | the CSV's columns by language |
| `deleteGlossary()` | `DeleteTerminology` | an empty answer |
| `translateDocument()` | `TranslateDocument`: `Document` (`Content` in base64, `ContentType`), the languages, `TerminologyNames`, `Settings` | `TranslatedDocument.Content`, `SourceLanguageCode` |

## Not verified

- TranslateText's and TranslateDocument's transactions per second: not published.
- The making-requests page of the API reference did not render; the protocol's names come from
  botocore's model (which also lists a newer CBOR protocol; JSON stays supported).
- The FAQ's "5,000 bytes per call" contradicts the API reference's and the quotas' 10,000: the
  latter is taken.
