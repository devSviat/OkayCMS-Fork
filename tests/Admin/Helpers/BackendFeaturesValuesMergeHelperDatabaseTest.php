<?php

namespace Admin\Helpers;

use Aura\Sql\ExtendedPdo;
use Okay\Admin\Helpers\BackendFeaturesValuesMergeHelper;
use Okay\Core\Config;
use Okay\Core\Languages;
use Okay\Core\Modules\Extender\ChainExtender;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Core\Modules\Extender\QueueExtender;
use Okay\Core\ServiceLocator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Злиття й перейменування значень властивості на живій базі. Фікстура створюється
 * в транзакції тесту й відкочується; хелпер усередині чужої транзакції працює через
 * SAVEPOINT, тож і його власний відкат перевіряється тут.
 *
 * Фікстура: «Кран» має дубль (той самий текст, інший translit), товар p3 має обидва.
 * «Ручка» зливається в «Ручку керування» лише в категорії grill: p6 уже має ціль,
 * p8 лежить і в grill, і в other, p7 — лише в other.
 */
class BackendFeaturesValuesMergeHelperDatabaseTest extends TestCase
{
    private ExtendedPdo $pdo;
    private string $px;
    private BackendFeaturesValuesMergeHelper $helper;
    private Languages $languages;
    private int $mainLangId;
    private ?int $otherLangId = null;

    /** @var array<string, int> */
    private array $ids = [];

    private bool $inTransaction = false;

    protected function setUp(): void
    {
        try {
            $this->pdo = ServiceLocator::getInstance()->getService(ExtendedPdo::class);
            $this->pdo->query('SELECT 1');
        } catch (\PDOException $e) {
            $this->markTestSkipped('немає бази: ' . $e->getMessage());
        }

        $prefix = (string) ServiceLocator::getInstance()->getService(Config::class)->get('db_prefix');
        if (!preg_match('/^[a-z0-9_]*$/i', $prefix)) {
            $this->fail('підозрілий префікс таблиць');
        }
        $this->px = $prefix;

        $this->languages = ServiceLocator::getInstance()->getService(Languages::class);
        $this->mainLangId = (int) $this->languages->getMainLanguage()->id;
        foreach ($this->languages->getAllLanguages() as $language) {
            if ((int) $language->id !== $this->mainLangId) {
                $this->otherLangId = (int) $language->id;
                break;
            }
        }

        $this->helper = ServiceLocator::getInstance()->getService(BackendFeaturesValuesMergeHelper::class);

        $this->pdo->beginTransaction();
        $this->inTransaction = true;
        $this->buildFixture();
    }

    protected function tearDown(): void
    {
        if ($this->inTransaction && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        RecordingRedirectsHandler::$calls = [];
        $this->forgetExtensions();
    }

    public function testPlanDescribesMergeWithoutWriting(): void
    {
        $before = $this->snapshot();

        $plan = $this->helper->plan($this->map([$this->duplicateRule()]));

        self::assertSame($before, $this->snapshot(), 'план не має нічого змінювати');

        $step = $plan['steps'][0];
        self::assertSame('merge', $step['type']);
        self::assertSame($this->ids['kran'], $step['target']['id']);
        self::assertSame([$this->ids['kran_dup']], array_column($step['sources'], 'id'));
        self::assertSame(2, $step['products_moved'], 'p3 і p4');
        self::assertSame(1, $step['already_had_target'], 'p3 уже має ціль');
        self::assertSame(2, $step['rows_deleted']);
        self::assertSame(1, $step['rows_added']);
        self::assertTrue($step['deletes_sources']);
        self::assertSame(['fx-other' => 2], $step['moved_by_category']);
        self::assertContains(
            ['lang' => $this->label($this->mainLangId), 'from' => 'kransp', 'to' => 'kran'],
            $step['url_changes']
        );
    }

    public function testDuplicateMergesIntoTargetAndKeepsItsIdAndTranslit(): void
    {
        $targetLangRows = $this->langRows($this->ids['kran']);

        $this->helper->apply($this->map([$this->duplicateRule()]));

        self::assertSame([], $this->valueRows($this->ids['kran_dup']), 'дубль видалено');
        self::assertSame([], $this->langRows($this->ids['kran_dup']), 'мовні рядки дубля видалено');
        self::assertSame([], $this->productIds($this->ids['kran_dup']));
        self::assertSame(
            [$this->ids['p1'], $this->ids['p2'], $this->ids['p3'], $this->ids['p4']],
            $this->productIds($this->ids['kran'])
        );
        self::assertSame('kran', $this->valueRows($this->ids['kran'])[0]['translit']);
        self::assertSame($targetLangRows, $this->langRows($this->ids['kran']), 'тексти й translit цілі не змінюються в жодній мові');
    }

    public function testProductWithBothValuesKeepsOneRow(): void
    {
        $this->helper->apply($this->map([$this->duplicateRule()]));

        $rows = (int) $this->pdo->fetchValue(
            "SELECT COUNT(*) FROM {$this->px}products_features_values WHERE product_id = :p AND value_id = :v",
            ['p' => $this->ids['p3'], 'v' => $this->ids['kran']]
        );
        self::assertSame(1, $rows);
    }

    public function testRenameChangesTextsButNotTranslit(): void
    {
        if ($this->otherLangId === null) {
            $this->markTestSkipped('потрібні дві мови');
        }

        $this->helper->apply($this->map([[
            'rename' => ['id' => $this->ids['kuller'], 'value' => 'Куллер'],
            'to' => [$this->label($this->mainLangId) => 'Кулер', $this->label($this->otherLangId) => 'Кулер ru'],
        ]]));

        self::assertSame('Кулер', $this->valueRows($this->ids['kuller'])[0]['value']);
        self::assertSame('kuler', $this->valueRows($this->ids['kuller'])[0]['translit']);
        $lang = $this->langRowsByLang($this->ids['kuller']);
        self::assertSame(['value' => 'Кулер', 'translit' => 'kuler'], $lang[$this->mainLangId]);
        self::assertSame(['value' => 'Кулер ru', 'translit' => 'kulerru'], $lang[$this->otherLangId]);
        self::assertSame([$this->ids['p5']], $this->productIds($this->ids['kuller']));
    }

    public function testRenameLeavesLanguagesThatAreNotGiven(): void
    {
        if ($this->otherLangId === null) {
            $this->markTestSkipped('потрібні дві мови');
        }

        $this->helper->apply($this->map([[
            'rename' => ['id' => $this->ids['kuller'], 'value' => 'Куллер'],
            'to' => [$this->label($this->mainLangId) => 'Кулер'],
        ]]));

        $lang = $this->langRowsByLang($this->ids['kuller']);
        self::assertSame('Кулер', $lang[$this->mainLangId]['value']);
        self::assertSame(['value' => 'Куллер ru', 'translit' => 'kulerru'], $lang[$this->otherLangId]);
    }

    public function testScopedMergeTouchesOnlyProductsOfGivenCategories(): void
    {
        $plan = $this->helper->apply($this->map([$this->scopedRule()]));

        self::assertSame([$this->ids['p7']], $this->productIds($this->ids['ruchka']), 'p7 лише в other — лишається');
        self::assertNotSame([], $this->valueRows($this->ids['ruchka']), 'значення-джерело не видаляється');
        self::assertSame(
            [$this->ids['p6'], $this->ids['p8']],
            $this->productIds($this->ids['ruchka_keruvannya'])
        );

        $step = $plan['steps'][0];
        self::assertFalse($step['deletes_sources']);
        self::assertSame(2, $step['products_moved']);
        self::assertSame(1, $step['already_had_target']);
        self::assertSame(['fx-other' => 1], $step['outside_scope'], 'p8 лежить і поза областю — про це звіт');
        self::assertSame(1, $step['left_in_source'][$this->ids['ruchka']]['count']);
    }

    public function testFailureAfterEarlierRulesRollsEverythingBack(): void
    {
        $before = $this->snapshot();
        $map = $this->map([$this->duplicateRule(), $this->scopedRule()]);
        $map['redirects'] = [['from' => '/catalog/fx-other/fxtype-kransp', 'to' => '/catalog/fx-other/fxtype-kran']];

        try {
            $this->helper->apply($map);
            self::fail('без обробника редиректів застосування мало впасти');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('redirect', strtolower($e->getMessage()));
        }

        self::assertTrue($this->pdo->inTransaction(), 'зовнішня транзакція тесту жива');
        self::assertSame($before, $this->snapshot(), 'злиття, що вже відбулися, відкочено');
    }

    public function testExceptionInsideSecondRuleRollsBackFirstRule(): void
    {
        $before = $this->snapshot();
        ServiceLocator::getInstance()->getService(ExtenderFacade::class)->newQueueExtension(
            ['class' => BackendFeaturesValuesMergeHelper::class, 'method' => 'renameValue'],
            ['class' => ThrowingMergeExtension::class, 'method' => 'fail']
        );

        try {
            $this->helper->apply($this->map([
                $this->duplicateRule(),
                ['rename' => ['id' => $this->ids['kuller'], 'value' => 'Куллер'], 'to' => [$this->label($this->mainLangId) => 'Кулер']],
            ]));
            self::fail('виняток розширення мав дійти до викликача');
        } catch (\LogicException $e) {
            self::assertSame('fx failure', $e->getMessage());
        }

        self::assertSame($before, $this->snapshot());
    }

    /** Стороння зміна посеред застосування — стан розходиться з планом, і звірка відкочує все. */
    public function testStateDivergingFromPlanRollsBack(): void
    {
        $before = $this->snapshot();
        DivergingMergeExtension::$productId = $this->ids['p7'];
        DivergingMergeExtension::$valueId = $this->ids['kran'];
        ServiceLocator::getInstance()->getService(ExtenderFacade::class)->newQueueExtension(
            ['class' => BackendFeaturesValuesMergeHelper::class, 'method' => 'mergeValues'],
            ['class' => DivergingMergeExtension::class, 'method' => 'addStrayLink']
        );

        try {
            $this->helper->apply($this->map([$this->duplicateRule()]));
            self::fail('розбіжність із планом мала зупинити застосування');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('розійшовся з планом', $e->getMessage());
        }

        self::assertSame($before, $this->snapshot());
    }

    public function testRedirectsReachHandlerOnlyWhenGiven(): void
    {
        ServiceLocator::getInstance()->getService(ExtenderFacade::class)->newChainExtension(
            ['class' => BackendFeaturesValuesMergeHelper::class, 'method' => 'storeRedirects'],
            ['class' => RecordingRedirectsHandler::class, 'method' => 'store']
        );

        $this->helper->apply($this->map([$this->scopedRule()]));
        self::assertSame([], RecordingRedirectsHandler::$calls, 'без редиректів у карті обробник не викликається');

        $map = $this->map([$this->duplicateRule()]);
        $map['redirects'] = [
            ['from' => '/catalog/fx-other/fxtype-kransp', 'to' => '/catalog/fx-other/fxtype-kran'],
            ['from' => 'ru/catalog/fx-other/fxtype-kransp', 'to' => 'ru/catalog/fx-other/fxtype-kran'],
        ];
        $plan = $this->helper->apply($map);

        self::assertSame([[
            ['from' => 'catalog/fx-other/fxtype-kransp', 'to' => 'catalog/fx-other/fxtype-kran'],
            ['from' => 'ru/catalog/fx-other/fxtype-kransp', 'to' => 'ru/catalog/fx-other/fxtype-kran'],
        ]], RecordingRedirectsHandler::$calls);
        self::assertCount(2, $plan['redirects']);
    }

    /** @return array<string, array{\Closure(self): array<string, mixed>}> */
    public static function invalidMapProvider(): array
    {
        return [
            'текст не збігається' => [static fn (self $t): array => $t->map([[
                'merge' => [['id' => $t->ids['kran_dup'], 'value' => 'Не Кран']],
                'into' => ['id' => $t->ids['kran'], 'value' => 'Кран'],
            ]])],
            'невідоме значення' => [static fn (self $t): array => $t->map([[
                'merge' => [['id' => 2147483000, 'value' => 'Кран']],
                'into' => ['id' => $t->ids['kran'], 'value' => 'Кран'],
            ]])],
            'значення іншої властивості' => [static fn (self $t): array => $t->map([[
                'merge' => [['id' => $t->ids['foreign'], 'value' => 'Кран']],
                'into' => ['id' => $t->ids['kran'], 'value' => 'Кран'],
            ]])],
            'ціль серед джерел' => [static fn (self $t): array => $t->map([[
                'merge' => [['id' => $t->ids['kran'], 'value' => 'Кран']],
                'into' => ['id' => $t->ids['kran'], 'value' => 'Кран'],
            ]])],
            'видалене раніше значення' => [static fn (self $t): array => $t->map([
                $t->duplicateRule(),
                ['rename' => ['id' => $t->ids['kran_dup'], 'value' => 'Кран'], 'to' => [$t->label($t->mainLangId) => 'Кран 2']],
            ])],
            'невідома категорія' => [static fn (self $t): array => $t->map([[
                'merge' => [['id' => $t->ids['ruchka'], 'value' => 'Ручка']],
                'into' => ['id' => $t->ids['ruchka_keruvannya'], 'value' => 'Ручка керування'],
                'categories' => [['id' => $t->ids['cat_grill'], 'url' => 'not-fx-grill']],
            ]])],
            'невідома мова' => [static fn (self $t): array => $t->map([[
                'rename' => ['id' => $t->ids['kuller'], 'value' => 'Куллер'],
                'to' => ['xx' => 'Кулер'],
            ]])],
            'порожній текст' => [static fn (self $t): array => $t->map([[
                'rename' => ['id' => $t->ids['kuller'], 'value' => 'Куллер'],
                'to' => [$t->label($t->mainLangId) => '  '],
            ]])],
            'інша властивість у карті' => [static fn (self $t): array => ['feature_id' => $t->ids['foreign_feature']] + $t->map([$t->duplicateRule()])],
            'редирект з адресою сайту' => [static fn (self $t): array => $t->map([$t->duplicateRule()]) + [
                'redirects' => [['from' => 'https://example.test/catalog/a', 'to' => 'catalog/b']],
            ]],
        ];
    }

    /** @param \Closure(self): array<string, mixed> $makeMap */
    #[DataProvider('invalidMapProvider')]
    public function testInvalidMapIsRejectedBeforeAnyWrite(\Closure $makeMap): void
    {
        $before = $this->snapshot();

        try {
            $this->helper->apply($makeMap($this));
            self::fail('карту мало бути відхилено');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame($before, $this->snapshot());
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @return array<string, mixed>
     */
    public function map(array $rules): array
    {
        return ['feature_id' => $this->ids['feature'], 'rules' => $rules];
    }

    /** @return array<string, mixed> */
    public function duplicateRule(): array
    {
        return [
            'merge' => [['id' => $this->ids['kran_dup'], 'value' => 'Кран']],
            'into' => ['id' => $this->ids['kran'], 'value' => 'Кран'],
        ];
    }

    /** @return array<string, mixed> */
    private function scopedRule(): array
    {
        return [
            'merge' => [['id' => $this->ids['ruchka'], 'value' => 'Ручка']],
            'into' => ['id' => $this->ids['ruchka_keruvannya'], 'value' => 'Ручка керування'],
            'categories' => [['id' => $this->ids['cat_grill'], 'url' => 'fx-grill']],
        ];
    }

    public function label(int $langId): string
    {
        return (string) $this->languages->getLangLabel($langId);
    }

    private function buildFixture(): void
    {
        $grill = $this->insert('categories', ['parent_id' => 0, 'name' => 'fx grill', 'url' => 'fx-grill', 'visible' => 1]);
        $other = $this->insert('categories', ['parent_id' => 0, 'name' => 'fx other', 'url' => 'fx-other', 'visible' => 1]);
        $this->ids += ['cat_grill' => $grill, 'cat_other' => $other];

        $this->ids['feature'] = $this->insert('features', ['name' => 'fx type', 'url' => 'fxtype', 'in_filter' => 1]);
        $this->ids['foreign_feature'] = $this->insert('features', ['name' => 'fx foreign', 'url' => 'fxforeign', 'in_filter' => 1]);

        $this->ids['kran'] = $this->value($this->ids['feature'], 'Кран', 'kran');
        $this->ids['kran_dup'] = $this->value($this->ids['feature'], 'Кран', 'kransp');
        $this->ids['kuller'] = $this->value($this->ids['feature'], 'Куллер', 'kuler');
        $this->ids['ruchka'] = $this->value($this->ids['feature'], 'Ручка', 'ruchka');
        $this->ids['ruchka_keruvannya'] = $this->value($this->ids['feature'], 'Ручка керування', 'ruchkaspkeruvannya');
        $this->ids['foreign'] = $this->value($this->ids['foreign_feature'], 'Кран', 'kran');

        $products = [
            'p1' => [[$other], ['kran']],
            'p2' => [[$other], ['kran']],
            'p3' => [[$other], ['kran', 'kran_dup']],
            'p4' => [[$other], ['kran_dup']],
            'p5' => [[$other], ['kuller']],
            'p6' => [[$grill], ['ruchka', 'ruchka_keruvannya']],
            'p7' => [[$other], ['ruchka']],
            'p8' => [[$grill, $other], ['ruchka']],
        ];
        foreach ($products as $key => [$categories, $values]) {
            $productId = $this->insert('products', ['name' => 'fx ' . $key, 'url' => 'fx-merge-' . $key, 'visible' => 1]);
            $this->ids[$key] = $productId;
            foreach ($categories as $position => $categoryId) {
                $this->insert('products_categories', ['product_id' => $productId, 'category_id' => $categoryId, 'position' => $position]);
            }
            foreach ($values as $value) {
                $this->insert('products_features_values', ['product_id' => $productId, 'value_id' => $this->ids[$value]]);
            }
        }
    }

    /** Основна мова дістає текст і translit як є, інші — з суфіксом ru, щоб мови розрізнялись. */
    private function value(int $featureId, string $text, string $translit): int
    {
        $id = $this->insert('features_values', ['feature_id' => $featureId, 'value' => $text, 'translit' => $translit]);
        foreach ($this->languages->getAllLanguages() as $language) {
            $isMain = (int) $language->id === $this->mainLangId;
            $this->insert('lang_features_values', [
                'lang_id' => (int) $language->id,
                'feature_value_id' => $id,
                'value' => $isMain ? $text : $text . ' ru',
                'translit' => $isMain ? $translit : $translit . 'ru',
            ]);
        }

        return $id;
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $valueIds = [
            $this->ids['kran'], $this->ids['kran_dup'], $this->ids['kuller'],
            $this->ids['ruchka'], $this->ids['ruchka_keruvannya'], $this->ids['foreign'],
        ];
        $snapshot = [];
        foreach ($valueIds as $id) {
            $snapshot[$id] = [$this->valueRows($id), $this->langRows($id), $this->productIds($id)];
        }

        return $snapshot;
    }

    /** @return list<array<string, mixed>> */
    private function valueRows(int $id): array
    {
        return $this->pdo->fetchAll(
            "SELECT id, feature_id, value, translit FROM {$this->px}features_values WHERE id = :id",
            ['id' => $id]
        );
    }

    /** @return list<array<string, mixed>> */
    private function langRows(int $id): array
    {
        return $this->pdo->fetchAll(
            "SELECT lang_id, value, translit FROM {$this->px}lang_features_values WHERE feature_value_id = :id ORDER BY lang_id",
            ['id' => $id]
        );
    }

    /** @return array<int, array{value: string, translit: string}> */
    private function langRowsByLang(int $id): array
    {
        $rows = [];
        foreach ($this->langRows($id) as $row) {
            $rows[(int) $row['lang_id']] = ['value' => (string) $row['value'], 'translit' => (string) $row['translit']];
        }

        return $rows;
    }

    /** @return list<int> */
    private function productIds(int $valueId): array
    {
        return array_map('intval', $this->pdo->fetchCol(
            "SELECT product_id FROM {$this->px}products_features_values WHERE value_id = :v ORDER BY product_id",
            ['v' => $valueId]
        ));
    }

    private function insert(string $table, array $row): int
    {
        $columns = array_keys($row);
        $this->pdo->perform(
            sprintf(
                'INSERT INTO %s%s (%s) VALUES (%s)',
                $this->px,
                $table,
                implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns)),
                implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns))
            ),
            $row
        );

        return (int) $this->pdo->lastInsertId();
    }

    /** Реєстр розширень — статичний на процес, тож тестові розширення не мають пережити тест. */
    private function forgetExtensions(): void
    {
        foreach ([ChainExtender::class, QueueExtender::class] as $extender) {
            $registry = new \ReflectionProperty($extender, 'triggers');
            $triggers = $registry->getValue();
            foreach (['storeRedirects', 'renameValue', 'mergeValues'] as $method) {
                unset($triggers[BackendFeaturesValuesMergeHelper::class . '::' . $method]);
            }
            $registry->setValue(null, $triggers);
        }
    }
}

class RecordingRedirectsHandler implements ExtensionInterface
{
    /** @var list<list<array{from: string, to: string}>> */
    public static array $calls = [];

    /** @param list<array{from: string, to: string}> $redirects */
    public function store(int $stored, array $redirects): int
    {
        self::$calls[] = $redirects;

        return $stored + count($redirects);
    }
}

class ThrowingMergeExtension implements ExtensionInterface
{
    public function fail(): void
    {
        throw new \LogicException('fx failure');
    }
}

class DivergingMergeExtension implements ExtensionInterface
{
    public static int $productId = 0;
    public static int $valueId = 0;

    public function addStrayLink(): void
    {
        $pdo = ServiceLocator::getInstance()->getService(ExtendedPdo::class);
        $px = (string) ServiceLocator::getInstance()->getService(Config::class)->get('db_prefix');
        $pdo->perform(
            "INSERT INTO {$px}products_features_values (product_id, value_id) VALUES (:p, :v)",
            ['p' => self::$productId, 'v' => self::$valueId]
        );
    }
}
