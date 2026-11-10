<?php

namespace Omnilang\Amazon;

use Omnilang\Capabilities;
use Omnilang\DocumentsInterface;
use Omnilang\Exception\InvalidArgumentException;
use Omnilang\Exception\NotSupportedException;
use Omnilang\Exception\ProviderException;
use Omnilang\GlossaryInterface;
use Omnilang\Http\Data;
use Omnilang\Http\JsonClient;
use Omnilang\Model\Document;
use Omnilang\Model\DocumentJob;
use Omnilang\Model\DocumentQuery;
use Omnilang\Model\DocumentStatus;
use Omnilang\Model\Formality;
use Omnilang\Model\Glossary;
use Omnilang\Model\Language;
use Omnilang\Model\LanguagePair;
use Omnilang\Model\SupportedLanguage;
use Omnilang\Model\Translation;
use Omnilang\Model\TranslationQuery;
use Omnilang\TranslatorInterface;
use Omnisense\Contracts\Policy\DeclaresUsagePolicyInterface;
use Omnisense\Contracts\Policy\UsagePolicy;

/**
 * Amazon Translate (docs.aws.amazon.com/translate/latest/APIReference, read
 * on 2026-11-10) over its JSON protocol (awsJson 1.1, target prefix
 * AWSShineFrontendService_20170701, as botocore's model of the service
 * names it): every call a POST to "/" with X-Amz-Target, signed with
 * Signature Version 4 (service "translate"). TranslateText - one text a
 * call, up to 10,000 bytes, plain text only; "auto" has Amazon Comprehend
 * say the source -, formality (FORMAL, INFORMAL), ListLanguages, custom
 * terminologies as glossaries (ImportTerminology, ListTerminologies,
 * GetTerminology and its file, DeleteTerminology; one a translation),
 * TranslateDocument (HTML, text, DOCX up to 100 KB, English on one side).
 * Amazon Translate has no detection call of its own.
 */
final class AmazonProvider implements TranslatorInterface, GlossaryInterface, DocumentsInterface, DeclaresUsagePolicyInterface
{
    public const POLICY = __DIR__.'/policy.json';

    public const TARGET = 'AWSShineFrontendService_20170701';

    public const DOCUMENT_TYPES = ['html', 'htm', 'txt', 'docx'];

    /** @param ?\Closure(): \DateTimeInterface $clock */
    public function __construct(
        private readonly JsonClient $json,
        private readonly SignatureV4 $signer,
        private readonly string $baseUri,
        private readonly bool $profanity = false,
        private readonly bool $brevity = false,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function getName(): string
    {
        return 'amazon';
    }

    public function getTitle(): string
    {
        return 'Amazon Translate';
    }

    public function usagePolicy(): UsagePolicy
    {
        return UsagePolicy::fromFile(self::POLICY);
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            $this->usagePolicy(),
            translate: true,
            glossary: true,
            documents: true,
            formality: true,
            glossaryInTranslation: true,
            glossaryCreate: true,
            detectsSource: true,
            documentTypes: self::DOCUMENT_TYPES,
            maxTexts: 1,
        );
    }

    public function translate(TranslationQuery $query): array
    {
        if ($query->isHtml()) {
            throw NotSupportedException::call('Amazon Translate', 'translate HTML with TranslateText (TranslateDocument takes text/html, English on one side)');
        }
        $glossary = $query->glossaryFor($this->getName());

        return array_map(function (string $text) use ($query, $glossary): Translation {
            $data = $this->call('TranslateText', array_filter([
                'Text' => $text,
                'SourceLanguageCode' => null === $query->source ? 'auto' : $query->source->code,
                'TargetLanguageCode' => $query->target->code,
                'TerminologyNames' => null === $glossary ? null : [$glossary],
                'Settings' => $this->settings($query->formality, true),
            ], static fn ($value) => null !== $value));

            return new Translation(
                \is_string($translated = $data->get('TranslatedText')) ? $translated : '',
                $query->target,
                'amazon',
                $query->source,
                null === $query->source ? Language::tryOf($data->string('SourceLanguageCode')) : null,
                $query->format,
                mb_strlen($text),
                null === $glossary || [] === $data->list('AppliedTerminologies') ? null : $glossary,
            );
        }, $query->texts);
    }

    public function languages(): array
    {
        $languages = [];
        $token = null;
        do {
            $data = $this->call('ListLanguages', array_filter(['DisplayLanguageCode' => 'en', 'MaxResults' => 500, 'NextToken' => $token]));
            foreach ($data->list('Languages') as $one) {
                if (null !== $language = Language::tryOf($one->string('LanguageCode'))) {
                    $languages[] = new SupportedLanguage($language, $one->string('LanguageName'));
                }
            }
            $token = $data->string('NextToken');
        } while (null !== $token);

        return $languages;
    }

    public function createGlossary(string $name, Language $source, Language $target, array $entries): Glossary
    {
        if (!preg_match('~^([A-Za-z0-9-]_?)+$~', $name) || \strlen($name) > 256) {
            throw new InvalidArgumentException(\sprintf('An Amazon terminology\'s name holds letters, digits, hyphens and single underscores ("rooms-fr-en"), not "%s".', $name));
        }
        $data = $this->call('ImportTerminology', [
            'Name' => $name,
            'MergeStrategy' => 'OVERWRITE',
            'TerminologyData' => ['File' => base64_encode(self::csv([$source->code, $target->code], $entries)), 'Format' => 'CSV', 'Directionality' => 'UNI'],
        ]);

        return $this->readGlossary($data->data('TerminologyProperties'));
    }

    public function glossaries(): array
    {
        $glossaries = [];
        $token = null;
        do {
            $data = $this->call('ListTerminologies', array_filter(['MaxResults' => 500, 'NextToken' => $token]));
            foreach ($data->list('TerminologyPropertiesList') as $one) {
                $glossaries[] = $this->readGlossary($one);
            }
            $token = $data->string('NextToken');
        } while (null !== $token);

        return $glossaries;
    }

    public function glossary(string $id): ?Glossary
    {
        $data = $this->terminology($id);

        return null === $data ? null : $this->readGlossary($data->data('TerminologyProperties'));
    }

    public function glossaryEntries(string $id, Language $source, Language $target): array
    {
        $location = $this->terminology($id)?->string('TerminologyDataLocation.Location');
        if (null === $location) {
            return [];
        }
        $file = $this->json->binary('GET', $location)['body'] ?? '';
        $rows = array_map(static fn (string $line) => str_getcsv($line, ',', '"', ''), preg_split('~\r\n|\n|\r~', trim(ltrim($file, "\xEF\xBB\xBF"))) ?: []);
        $header = array_map(static fn ($cell) => Language::tryOf(\is_string($cell) ? trim($cell) : null), array_shift($rows) ?? []);
        $from = $to = null;
        foreach ($header as $column => $language) {
            if (null !== $language && null === $from && $language->sameLanguage($source)) {
                $from = $column;
            } elseif (null !== $language && null === $to && $language->sameLanguage($target)) {
                $to = $column;
            }
        }
        $entries = [];
        if (null !== $from && null !== $to) {
            foreach ($rows as $row) {
                $term = $row[$from] ?? null;
                $translation = $row[$to] ?? null;
                if (\is_string($term) && \is_string($translation) && '' !== trim($term)) {
                    $entries[trim($term)] = trim($translation);
                }
            }
        }

        return $entries;
    }

    public function deleteGlossary(string $id): void
    {
        $this->call('DeleteTerminology', ['Name' => $id], true);
    }

    public function translateDocument(DocumentQuery $query): DocumentJob
    {
        $glossary = $query->glossaryFor($this->getName());
        $type = 'htm' === $query->document->extension() ? 'text/html' : $query->document->mimeType;
        $data = $this->call('TranslateDocument', array_filter([
            'Document' => ['Content' => base64_encode($query->document->content), 'ContentType' => $type],
            'SourceLanguageCode' => null === $query->source ? 'auto' : $query->source->code,
            'TargetLanguageCode' => $query->target->code,
            'TerminologyNames' => null === $glossary ? null : [$glossary],
            'Settings' => $this->settings($query->formality, false),
        ], static fn ($value) => null !== $value));
        $bytes = base64_decode((string) $data->string('TranslatedDocument.Content'), true);
        if (false === $bytes) {
            throw ProviderException::unexpected('Amazon Translate', 'TranslateDocument', 'No TranslatedDocument.Content.');
        }
        $result = new Document($bytes, $query->translatedName(), $type);

        return new DocumentJob('amazon-'.substr(hash('sha256', $query->document->content.$query->target->code), 0, 16), 'amazon', DocumentStatus::Done, $query->target, $query->source, filename: $result->filename, result: $result, detected: null === $query->source ? Language::tryOf($data->string('SourceLanguageCode')) : null);
    }

    public function documentStatus(DocumentJob $job): DocumentJob
    {
        return $job;
    }

    public function documentResult(DocumentJob $job): Document
    {
        return $job->result ?? throw NotSupportedException::call('Amazon Translate', 'keep a document: TranslateDocument answers it at once');
    }

    /**
     * One call of the JSON protocol, signed.
     *
     * @param array<string, mixed> $payload
     */
    private function call(string $operation, array $payload, bool $empty = false): Data
    {
        $body = json_encode([] === $payload ? new \stdClass() : $payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $headers = $this->signer->sign('POST', '/', [], [
            'Host' => (string) parse_url($this->baseUri, \PHP_URL_HOST),
            'Content-Type' => 'application/x-amz-json-1.1',
            'X-Amz-Target' => self::TARGET.'.'.$operation,
        ], $body, null === $this->clock ? new \DateTimeImmutable() : ($this->clock)());
        unset($headers['Host']);
        if ($empty) {
            $this->json->binary('POST', $this->baseUri.'/', ['body' => $body], $headers) ?? throw ProviderException::answered('Amazon Translate', $operation, 404);

            return Data::of([]);
        }

        return $this->json->request('POST', $this->baseUri.'/', ['body' => $body], $headers) ?? throw ProviderException::answered('Amazon Translate', $operation, 404);
    }

    /** GetTerminology's answer; null when Amazon knows no terminology of that name. */
    private function terminology(string $name): ?Data
    {
        try {
            return $this->call('GetTerminology', ['Name' => $name, 'TerminologyDataFormat' => 'CSV']);
        } catch (ProviderException $e) {
            if (400 === $e->status && str_contains($e->getMessage(), 'ResourceNotFoundException')) {
                return null;
            }
            throw $e;
        }
    }

    /** @return ?array<string, string> */
    private function settings(?Formality $formality, bool $text): ?array
    {
        $settings = array_filter([
            'Formality' => match ($formality) {
                Formality::Formal => 'FORMAL',
                Formality::Informal => 'INFORMAL',
                null => null,
            },
            'Profanity' => $this->profanity ? 'MASK' : null,
            'Brevity' => $text && $this->brevity ? 'ON' : null,
        ]);

        return [] === $settings ? null : $settings;
    }

    private function readGlossary(Data $data): Glossary
    {
        $name = $data->string('Name') ?? throw ProviderException::unexpected('Amazon Translate', 'a terminology', 'No Name.');
        $source = Language::tryOf($data->string('SourceLanguageCode'));
        $targets = array_values(array_filter(array_map(Language::tryOf(...), $data->strings('TargetLanguageCodes'))));
        $count = $data->int('TermCount');
        $created = $data->float('CreatedAt');

        return new Glossary(
            $name,
            $data->string('Description') ?? $name,
            'amazon',
            null === $source ? [] : array_map(static fn (Language $target) => new LanguagePair($source, $target, 1 === \count($targets) ? $count : null), $targets),
            null === $created ? null : (\DateTimeImmutable::createFromFormat('U.u', \sprintf('%.6F', $created)) ?: null),
        );
    }

    /**
     * Entries as the CSV Amazon imports: a first row of language codes, a
     * term and its translation a row.
     *
     * @param list<string>          $languages
     * @param array<string, string> $entries
     */
    private static function csv(array $languages, array $entries): string
    {
        $rows = [$languages];
        foreach ($entries as $term => $translation) {
            $rows[] = [(string) $term, $translation];
        }

        return implode("\n", array_map(static fn (array $row) => implode(',', array_map(static fn (string $cell) => preg_match('~[",\r\n]~', $cell) || $cell !== trim($cell) ? '"'.str_replace('"', '""', $cell).'"' : $cell, $row)), $rows))."\n";
    }
}
