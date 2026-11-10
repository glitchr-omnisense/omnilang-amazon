# omnilang/amazon

[omnisense/omnilang](https://github.com/glitchr-omnisense/omnilang)'s Amazon Translate, under any
PHP host and without the AWS SDK: its JSON protocol, every call signed with Signature Version 4 by
the package itself (checked against AWS's own test suite).

- **translate** (`TranslateText`): one text a call (up to 10,000 bytes), the source detected by
  `auto` (Amazon Comprehend), formality (`FORMAL`, `INFORMAL`), profanity masked, brevity, a custom
  terminology; the languages (`ListLanguages`). Plain text only: HTML goes through documents;
- **glossaries** (custom terminologies): imported from entries (CSV), listed, read (their file),
  applied, deleted;
- **documents** (`TranslateDocument`): HTML, text, DOCX up to 100 KB, English on one side, at once.

Amazon Translate has no detection call of its own.

```php
use Omnilang\Amazon\AmazonFactory;
use Omnilang\Model\{Formality, TranslationQuery};

$amazon = (new AmazonFactory($httpClient))->create([
    'region' => 'eu-west-1',
    'access_key_id' => 'env:AWS_ACCESS_KEY_ID',
    'secret_access_key' => 'env:AWS_SECRET_ACCESS_KEY',
]);
echo $amazon->translate(TranslationQuery::text('Vous êtes les bienvenus', 'de', formality: Formality::Informal))[0]->text;
```

Options: `region`, `access_key_id`, `secret_access_key` (required, by reference), `session_token`
(temporary credentials), `base_uri` (`https://translate.<region>.amazonaws.com`), `profanity`,
`brevity`, `timeout`; `policy`, `usage`. The IAM policy allows the `translate:*` actions called.

**Policy** ([policy.json](policy.json), read on 2026-11-10): the terminology calls' rates (5 or 10 a
second) enforced; TranslateText's not published; throttling answers 400. Attribution not required
(AWS suggests "Machine Translation"). **Amazon may store and use text inputs to improve its
services** unless the account's AWS Organizations AI services opt-out policy says no. Free tier:
2,000,000 characters a month for 12 months, none for real-time documents.

See [docs/index.md](docs/index.md). MIT licence.
