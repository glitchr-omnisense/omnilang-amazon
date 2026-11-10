<?php

namespace Omnilang\Amazon\Tests;

use Omnilang\Amazon\AmazonFactory;
use Omnilang\Amazon\AmazonProvider;
use Omnilang\Amazon\SignatureV4;
use Omnilang\Exception\InvalidArgumentException;
use Omnilang\Exception\NotSupportedException;
use Omnilang\Exception\ProviderException;
use Omnilang\Model\Document;
use Omnilang\Model\DocumentQuery;
use Omnilang\Model\Formality;
use Omnilang\Model\Language;
use Omnilang\Model\TranslationQuery;
use Omnilang\Testing\MockHttp;
use PHPUnit\Framework\TestCase;

/**
 * Amazon Translate's answers written from its API reference (TranslateText,
 * TranslateDocument, ListLanguages, ImportTerminology, ListTerminologies,
 * GetTerminology, DeleteTerminology; read on 2026-11-10); no call made
 * (credentials are needed). Every test checks the exact request: the
 * target, the JSON body, and its signature, made again over the bytes sent.
 */
final class AmazonProviderTest extends TestCase
{
    private const KEY_ID = 'AKIAIOSFODNN7EXAMPLE';
    private const SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
    private const URL = 'https://translate.eu-west-1.amazonaws.com/';
    private const FIXTURES = __DIR__.'/Fixtures/';

    public function testATextTranslatedAndSigned(): void
    {
        $http = self::http();
        $translations = self::provider($http)->translate(TranslationQuery::text(['Chambre double vue jardin', 'Petit-déjeuner compris'], 'en', formality: Formality::Formal));

        self::assertSame(['Double room with garden view', 'fr', null], [$translations[0]->text, $translations[0]->detected?->code, $translations[0]->source]);
        self::assertSame(['POST '.self::URL, 'POST '.self::URL], $http->lines(), 'one text a call');
        self::assertSame(['Text' => 'Petit-déjeuner compris', 'SourceLanguageCode' => 'auto', 'TargetLanguageCode' => 'en', 'Settings' => ['Formality' => 'FORMAL']], $http->json());
        self::assertSame(['AWSShineFrontendService_20170701.TranslateText', 'application/x-amz-json-1.1', '20261110T090000Z'], [$http->header('X-Amz-Target'), $http->header('Content-Type'), $http->header('X-Amz-Date')]);
        self::assertSigned($http);
        self::assertStringNotContainsString(self::SECRET, $http->dump(), 'the secret never travels');
    }

    public function testATerminologyAppliedAndATemporaryCredentialsToken(): void
    {
        $http = self::http();
        $translation = self::provider($http, ['session_token' => 'not-a-real-session-token', 'profanity' => true])->translate(new TranslationQuery("Chambre d'hôtes", 'en', 'fr', glossaries: ['amazon' => 'rooms-fr-en']))[0];

        self::assertSame(['Bed and breakfast', 'rooms-fr-en', null], [$translation->text, $translation->glossary, $translation->detected]);
        self::assertSame(['Text' => "Chambre d'hôtes", 'SourceLanguageCode' => 'fr', 'TargetLanguageCode' => 'en', 'TerminologyNames' => ['rooms-fr-en'], 'Settings' => ['Profanity' => 'MASK']], $http->json());
        self::assertSame('not-a-real-session-token', $http->header('X-Amz-Security-Token'));
        self::assertStringContainsString('SignedHeaders=content-type;host;x-amz-date;x-amz-security-token;x-amz-target,', (string) $http->header('Authorization'));
        self::assertSigned($http, 'not-a-real-session-token');

        $this->expectException(NotSupportedException::class);
        self::provider($http)->translate(TranslationQuery::html('<p>Merci</p>', 'en'));
    }

    public function testTheLanguagesPageByPage(): void
    {
        $http = self::http();

        self::assertSame(['en', 'fr-CA', 'zh-TW'], array_map(static fn ($l) => $l->language->code, self::provider($http)->languages()), '"auto" is no language');
        self::assertSame(['DisplayLanguageCode' => 'en', 'MaxResults' => 500, 'NextToken' => 'next-page'], $http->json());
        self::assertSame('AWSShineFrontendService_20170701.ListLanguages', $http->header('X-Amz-Target'));
    }

    public function testATerminologyImportedListedReadAndDeleted(): void
    {
        $http = self::http();
        $provider = self::provider($http);
        $glossary = $provider->createGlossary('rooms-fr-en', Language::of('fr'), Language::of('en'), ["Chambre d'hôtes" => 'Bed and breakfast', 'Table, d\'hôtes' => "Host's table"]);

        self::assertSame(['rooms-fr-en', 2, '2026-11-10T09:00:00+00:00'], [$glossary->id, $glossary->entries(), $glossary->createdAt?->format(\DATE_ATOM)]);
        $import = $http->json();
        self::assertIsArray($import);
        self::assertSame(['Name' => 'rooms-fr-en', 'MergeStrategy' => 'OVERWRITE', 'TerminologyData' => ['File' => base64_encode("fr,en\nChambre d'hôtes,Bed and breakfast\n\"Table, d'hôtes\",Host's table\n"), 'Format' => 'CSV', 'Directionality' => 'UNI']], $import);

        $all = $provider->glossaries();
        self::assertSame([['rooms-fr-en', 'rooms-fr-en'], ['brands', 'Brands']], array_map(static fn ($g) => [$g->id, $g->name], $all));
        self::assertTrue($all[1]->covers('en', 'ja'));
        self::assertNull($all[1]->entries(), 'a count for two targets together');

        self::assertSame(["Chambre d'hôtes" => 'Bed and breakfast', "Table, d'hôtes" => "Host's table"], $provider->glossaryEntries('rooms-fr-en', Language::of('fr'), Language::of('en')));
        self::assertSame('GET https://aws-translate-terminology-prod-eu-west-1.s3.eu-west-1.amazonaws.com/123456789012/rooms-fr-en.csv?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=ASIAEXAMPLE&X-Amz-Signature=abcdef0123456789', $http->lines()[3]);
        self::assertNull($http->header('Authorization'), 'the presigned file needs no signature of ours');
        self::assertNull($provider->glossary('missing'));
        $provider->deleteGlossary('rooms-fr-en');
        self::assertSame(['Name' => 'rooms-fr-en'], $http->json());
        self::assertSame('AWSShineFrontendService_20170701.DeleteTerminology', $http->header('X-Amz-Target'));

        $this->expectException(InvalidArgumentException::class);
        $provider->createGlossary('rooms fr', Language::of('fr'), Language::of('en'), []);
    }

    public function testADocumentTranslatedAtOnce(): void
    {
        $http = self::http();
        $job = self::provider($http)->translateDocument(new DocumentQuery(new Document('<p>Menu du jour</p>', 'menu.html'), 'en', formality: Formality::Informal));

        self::assertSame([true, '<p>Menu of the day</p>', 'menu.en.html', 'fr'], [$job->isDone(), $job->result?->content, $job->result?->filename, $job->detected?->code]);
        self::assertSame(['Document' => ['Content' => base64_encode('<p>Menu du jour</p>'), 'ContentType' => 'text/html'], 'SourceLanguageCode' => 'auto', 'TargetLanguageCode' => 'en', 'Settings' => ['Formality' => 'INFORMAL']], $http->json());
    }

    public function testAnErrorKeepsTheSecretOut(): void
    {
        $http = MockHttp::routes(['~^POST https://translate~' => [400, '{"__type": "com.amazonaws.translate.v20170701#UnsupportedLanguagePairException", "message": "Unsupported language pair: fr to xx"}', ['Content-Type' => 'application/x-amz-json-1.1']]]);
        try {
            self::provider($http)->translate(TranslationQuery::text('Bonjour', 'xx'));
            self::fail('A 400 is an error.');
        } catch (ProviderException $e) {
            self::assertSame(400, $e->status);
            self::assertStringContainsString('UnsupportedLanguagePairException', $e->getMessage());
            self::assertStringNotContainsString(self::SECRET, $e->getMessage().$e->getTraceAsString());
        }
    }

    private static function assertSigned(MockHttp $http, ?string $token = null): void
    {
        $sent = $http->request();
        $headers = ['Host' => 'translate.eu-west-1.amazonaws.com', 'Content-Type' => $sent['headers']['content-type'] ?? '', 'X-Amz-Target' => $sent['headers']['x-amz-target'] ?? ''];
        $signed = (new SignatureV4(self::KEY_ID, self::SECRET, 'eu-west-1', 'translate', $token))->sign('POST', '/', [], $headers, $sent['body'], new \DateTimeImmutable('2026-11-10T09:00:00Z'));

        self::assertSame($signed['Authorization'], $http->header('Authorization'), 'signed over the bytes sent');
        self::assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20261110/eu-west-1/translate/aws4_request, SignedHeaders=content-type;host;x-amz-date;', (string) $http->header('Authorization'));
    }

    private static function http(): MockHttp
    {
        return MockHttp::routes([
            '~^POST https://translate\.eu-west-1\.amazonaws\.com/$~' => static function (string $method, string $url, string $body): array {
                $data = json_decode($body, true);
                $data = \is_array($data) ? $data : [];

                return match (true) {
                    isset($data['Document']) => [200, '@'.self::FIXTURES.'document.json'],
                    isset($data['TerminologyNames']) => [200, '@'.self::FIXTURES.'translate-terminology.json'],
                    isset($data['Text']) => [200, '@'.self::FIXTURES.'translate.json'],
                    isset($data['DisplayLanguageCode']) => [200, '@'.self::FIXTURES.(isset($data['NextToken']) ? 'languages-2.json' : 'languages-1.json')],
                    isset($data['TerminologyData']) => [200, '@'.self::FIXTURES.'terminology.json'],
                    isset($data['TerminologyDataFormat']) => 'missing' === ($data['Name'] ?? null)
                        ? [400, '{"__type": "com.amazonaws.translate.v20170701#ResourceNotFoundException", "message": "Terminology missing does not exist"}']
                        : [200, '@'.self::FIXTURES.'terminology-get.json'],
                    isset($data['MaxResults']) => [200, '@'.self::FIXTURES.'terminologies.json'],
                    isset($data['Name']) => [200, ''],
                    default => [400, '{"__type": "UnknownOperationException"}'],
                };
            },
            '~^GET https://aws-translate-terminology-prod-eu-west-1\.s3\.eu-west-1\.amazonaws\.com/~' => [200, "\xEF\xBB\xBFfr,en\nChambre d'hôtes,Bed and breakfast\n\"Table, d'hôtes\",Host's table\n", ['Content-Type' => 'text/csv']],
        ]);
    }

    /** @param array<string, mixed> $options */
    private static function provider(MockHttp $http, array $options = []): AmazonProvider
    {
        $provider = (new AmazonFactory($http->client, static fn () => new \DateTimeImmutable('2026-11-10T09:00:00Z')))->create($options + ['region' => 'eu-west-1', 'access_key_id' => self::KEY_ID, 'secret_access_key' => self::SECRET]);
        self::assertInstanceOf(AmazonProvider::class, $provider);

        return $provider;
    }
}
