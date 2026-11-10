<?php

namespace Omnilang\Amazon\Tests;

use Omnilang\Amazon\AmazonProvider;
use Omnilang\Testing\BareTestCase;

/** omnilang/amazon's classes load with omnisense/omnilang, omnisense/contracts and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return AmazonProvider::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }
}
