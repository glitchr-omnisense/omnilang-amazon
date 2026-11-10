<?php

namespace Omnilang\Amazon;

use Omnilang\Config;
use Omnilang\Exception\InvalidConfigException;
use Omnilang\Http\JsonClient;
use Omnilang\ProviderFactory;
use Omnilang\ProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "amazon": Amazon Translate. Options region (required: "eu-west-1"...),
 * access_key_id and secret_access_key (required, by reference:
 * "env:AWS_ACCESS_KEY_ID", "env:AWS_SECRET_ACCESS_KEY", or the values the
 * application reads from its vault - an IAM user's or role's, allowed the
 * translate:* actions it calls), session_token (temporary credentials',
 * by reference too), base_uri (https://translate.<region>.amazonaws.com;
 * a FIPS or .api.aws host), profanity (true: masked), brevity (true:
 * shorter translations, text only), timeout (seconds); policy, usage.
 *
 * $clock gives the moment each request is signed at (a test's fixed one).
 */
final class AmazonFactory extends ProviderFactory
{
    /** @param ?\Closure(): \DateTimeInterface $clock */
    public function __construct(private readonly ?HttpClientInterface $http = null, private readonly ?\Closure $clock = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilang.factory_name' => 'amazon',
            'omnilang.factory_title' => 'Amazon Translate',
            'omnilang.required_options' => ['region', 'access_key_id', 'secret_access_key'],
            'session_token' => null,
            'base_uri' => null,
            'profanity' => false,
            'brevity' => false,
            'timeout' => null,
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        $region = (string) $c->string('region');
        if (!preg_match('~^[a-z]{2}(-[a-z]+)+-\d$~', $region)) {
            throw new InvalidConfigException(\sprintf('The "amazon" provider\'s region is an AWS region ("eu-west-1"), not "%s".', $region));
        }
        $base = rtrim($c->string('base_uri') ?? 'https://translate.'.$region.'.amazonaws.com', '/');
        if (!preg_match('~^https://([^/\s]+)$~', $base)) {
            throw new InvalidConfigException(\sprintf('The "amazon" provider\'s base_uri is a host over HTTPS (https://translate.eu-west-1.amazonaws.com), not "%s".', $base));
        }
        $secret = (string) $c->secret('secret_access_key');
        $token = $c->secret('session_token');
        $signer = new SignatureV4((string) $c->secret('access_key_id'), $secret, $region, 'translate', $token);
        $http = $this->http ?? HttpClient::create(['timeout' => 60]);
        if (null !== $timeout = $c->string('timeout')) {
            $http = $http->withOptions(['timeout' => (float) $timeout]);
        }

        return new AmazonProvider(new JsonClient($http, 'Amazon Translate', [], $c->backoff(), array_values(array_filter([$secret, $token]))), $signer, $base, $c->bool('profanity'), $c->bool('brevity'), $this->clock);
    }
}
