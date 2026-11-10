<?php

namespace Omnilang\Amazon\Tests;

use Omnilang\Amazon\AmazonFactory;
use Omnilang\Exception\UsageLimitReachedException;
use Omnilang\Http\Data;
use Omnilang\Registry;
use Omnilang\Testing\MockHttp;
use Omnisense\Contracts\Policy\Permission;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;

/** Amazon's published rates (the terminology calls) enforced before any call; its data use said in the policy. */
final class PolicyTest extends TestCase
{
    public function testTheDeclaredPolicy(): void
    {
        $policy = self::registry(MockHttp::routes([]))->policy('amazon');
        $composer = Data::of(json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true, 512, \JSON_THROW_ON_ERROR));

        self::assertSame('policy.json', $composer->string('extra.omnisense.policy'));
        self::assertEquals(UsagePolicy::fromFile(__DIR__.'/../policy.json'), $policy);
        self::assertSame([], $policy->limitsFor('translate'), 'TranslateText\'s rate is not published');
        self::assertSame(5, $policy->limitsFor('deleteGlossary')[0]->max ?? null);
        self::assertSame([Permission::Unknown, false, 'Machine Translation'], [$policy->rights->commercial, $policy->rights->attribution->required, $policy->rights->attribution->text]);
        self::assertStringContainsString('opt-out policy', (string) $policy->provenance->notes);
    }

    public function testTheDeleteRateIsHeldToFiveASecond(): void
    {
        $http = MockHttp::routes(['~^POST ~' => [200, '']]);
        $glossaries = self::registry($http)->glossary('amazon');
        for ($i = 0; $i < 5; ++$i) {
            $glossaries->deleteGlossary('old-'.$i);
        }

        try {
            $glossaries->deleteGlossary('old-5');
            self::fail('The sixth delete of the second waits.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(1, $e->retryAfter);
        }
        self::assertCount(5, $http->requests);
        self::assertSame('AWSShineFrontendService_20170701.DeleteTerminology', $http->header('X-Amz-Target'));
    }

    private static function registry(MockHttp $http): Registry
    {
        return new Registry([new AmazonFactory($http->client)], ['amazon' => ['factory' => 'amazon', 'options' => ['region' => 'eu-west-1', 'access_key_id' => 'AKIDEXAMPLE', 'secret_access_key' => 'not-a-real-secret']]]);
    }
}
