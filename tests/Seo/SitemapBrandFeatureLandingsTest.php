<?php

namespace Seo;

use Okay\Core\EntityFactory;
use Okay\Core\Response;
use Okay\Core\Settings;
use Okay\Helpers\BrandFeatureLandingsHelper;
use Okay\Helpers\CanonicalHelper;
use Okay\Helpers\FilterHelper;
use Okay\Helpers\MainHelper;
use Okay\Helpers\MetaRobotsHelper;
use Okay\Helpers\SiteMapHelper;
use PHPUnit\Framework\TestCase;

/**
 * Посадкові «бренд + властивість» потрапляють у карту рівно тим набором, який
 * BrandFeatureLandingsHelper відкриває для індексу; без позначених властивостей — жодної.
 */
class SitemapBrandFeatureLandingsTest extends TestCase
{
    private $argv;

    public function testWritesEveryLanding(): void
    {
        $landings = [
            (object)['url' => 'https://example.com/catalog/a/brand-x/type-y'],
            (object)['url' => 'https://example.com/catalog/b/brand-x/type-z'],
        ];

        $this->assertSame(
            [
                ['url' => 'https://example.com/catalog/a/brand-x/type-y', 'changefreq' => 'weekly', 'priority' => '0.5'],
                ['url' => 'https://example.com/catalog/b/brand-x/type-z', 'changefreq' => 'weekly', 'priority' => '0.5'],
            ],
            $this->written($landings)
        );
    }

    public function testWritesNothingWithoutLandings(): void
    {
        $this->assertSame([], $this->written([]));
    }

    public function testControllerCallsTheProcedure(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/Okay/Controllers/SiteMapController.php');

        $this->assertStringContainsString('writeBrandFeatureLandingsProcedure()', $source);
    }

    protected function setUp(): void
    {
        $this->argv = $GLOBALS['argv'] ?? null;
        $GLOBALS['argv'] = ['ok', 'root_url=https://example.com'];
    }

    protected function tearDown(): void
    {
        $GLOBALS['argv'] = $this->argv;
    }

    private function written(array $landings): array
    {
        $landingsHelper = $this->createStub(BrandFeatureLandingsHelper::class);
        $landingsHelper->method('getSitemapLandings')->willReturn($landings);

        $language = new \stdClass();
        $language->label = 'ua';
        $mainHelper = $this->createStub(MainHelper::class);
        $mainHelper->method('getCurrentLanguage')->willReturn($language);

        $helper = new class(
            $this->createStub(EntityFactory::class),
            $this->createStub(Response::class),
            $mainHelper,
            $this->createStub(Settings::class),
            $this->createStub(FilterHelper::class),
            $this->createStub(MetaRobotsHelper::class),
            $this->createStub(CanonicalHelper::class),
            $landingsHelper
        ) extends SiteMapHelper {
            public array $written = [];

            public function write(array $params, $countUrl = false)
            {
                $this->written[] = $params;
            }
        };

        $helper->writeBrandFeatureLandingsProcedure();

        return $helper->written;
    }
}
