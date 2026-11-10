<?php

namespace Omnilang\Amazon;

/**
 * AWS Signature Version 4 (docs.aws.amazon.com/IAM/latest/UserGuide/
 * reference_sigv-create-signed-request.html): the canonical request, the
 * string to sign, the signing key derived from the secret for the day,
 * the region and the service, and the Authorization header made of them -
 * written here so that the provider needs no SDK. The secret never leaves
 * this class; only its derived signature does.
 */
final class SignatureV4
{
    public const ALGORITHM = 'AWS4-HMAC-SHA256';

    public function __construct(
        private readonly string $accessKeyId,
        #[\SensitiveParameter]
        private readonly string $secretAccessKey,
        private readonly string $region,
        private readonly string $service,
        #[\SensitiveParameter]
        private readonly ?string $sessionToken = null,
    ) {
    }

    /**
     * The headers to send, signed: those given, X-Amz-Date, the session's
     * X-Amz-Security-Token when there is one, Authorization.
     *
     * @param array<string, string> $headers the request's own (Host among them)
     * @param array<string, string> $query
     *
     * @return array<string, string>
     */
    public function sign(string $method, string $path, array $query, array $headers, string $body, \DateTimeInterface $at): array
    {
        $time = \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'));
        $amzDate = $time->format('Ymd\THis\Z');
        $day = $time->format('Ymd');
        $headers['X-Amz-Date'] = $amzDate;
        if (null !== $this->sessionToken) {
            $headers['X-Amz-Security-Token'] = $this->sessionToken;
        }
        $canonical = [];
        foreach ($headers as $name => $value) {
            $canonical[strtolower($name)] = trim((string) preg_replace('~\s+~', ' ', $value));
        }
        ksort($canonical, \SORT_STRING);
        $signed = implode(';', array_keys($canonical));
        ksort($query, \SORT_STRING);
        $request = implode("\n", [
            strtoupper($method),
            '' === $path ? '/' : implode('/', array_map(rawurlencode(...), explode('/', $path))),
            implode('&', array_map(static fn (string $k, string $v) => rawurlencode($k).'='.rawurlencode($v), array_keys($query), $query)),
            implode('', array_map(static fn (string $k, string $v) => $k.':'.$v."\n", array_keys($canonical), $canonical)),
            $signed,
            hash('sha256', $body),
        ]);
        $scope = $day.'/'.$this->region.'/'.$this->service.'/aws4_request';
        $toSign = implode("\n", [self::ALGORITHM, $amzDate, $scope, hash('sha256', $request)]);
        $key = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', $this->service, hash_hmac('sha256', $this->region, hash_hmac('sha256', $day, 'AWS4'.$this->secretAccessKey, true), true), true), true);
        $headers['Authorization'] = \sprintf('%s Credential=%s/%s, SignedHeaders=%s, Signature=%s', self::ALGORITHM, $this->accessKeyId, $scope, $signed, hash_hmac('sha256', $toSign, $key));

        return $headers;
    }
}
