<?php


namespace Okay\Admin\Helpers;


use Aura\Sql\ExtendedPdo;
use Okay\Core\Database;
use Okay\Core\EntityFactory;
use Okay\Core\Languages;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Core\QueryFactory;
use Okay\Entities\FeaturesEntity;
use Okay\Entities\FeaturesValuesEntity;

/**
 * Зливає й перейменовує значення однієї властивості за картою (докладно — docs/feature-values-merge.md).
 *
 * Ціль злиття зберігає свій id і translit, тож її адреси фільтрів не змінюються; зникають адреси
 * джерел. Злиття без категорій переносить усі товари джерела й видаляє його, злиття з категоріями —
 * лише товари, що лежать хоча б в одній із них, а джерело лишається.
 *
 * plan() лише читає. apply() будує той самий план усередині транзакції, виконує його й звіряє
 * підсумковий стан із планом: будь-яка розбіжність чи виняток відкочує все.
 */
class BackendFeaturesValuesMergeHelper
{
    private const string SAVEPOINT = 'features_values_merge';

    private FeaturesValuesEntity $featuresValuesEntity;
    private FeaturesEntity $featuresEntity;

    public function __construct(
        EntityFactory $entityFactory,
        private QueryFactory $queryFactory,
        private Database $db,
        private Languages $languages,
        private ExtendedPdo $pdo
    ) {
        $this->featuresValuesEntity = $entityFactory->get(FeaturesValuesEntity::class);
        $this->featuresEntity = $entityFactory->get(FeaturesEntity::class);
    }

    /**
     * Перевіряє карту на поточних даних і описує кожен крок. Нічого не пише.
     *
     * @param array<string, mixed> $map розібраний JSON карти
     * @return array<string, mixed> feature, languages, steps, redirects, expected
     * @throws \InvalidArgumentException карта не відповідає даним
     */
    public function plan(array $map)
    {
        $map = $this->normalizeMap($map);

        $feature = $this->featuresEntity->get($map['feature_id']);
        if (empty($feature->id)) {
            throw new \InvalidArgumentException("Властивості #{$map['feature_id']} немає.");
        }

        $languages = [];
        $mainLanguage = $this->languages->getMainLanguage();
        foreach ($this->languages->getAllLanguages() as $language) {
            $languages[(int)$language->id] = [
                'label' => (string)$language->label,
                'prefix' => (string)$this->languages->getLangLink((int)$language->id),
                'main' => (int)$language->id === (int)$mainLanguage->id,
            ];
        }
        $langIdsByLabel = array_flip(array_map(static fn (array $l): string => $l['label'], $languages));

        $valueIds = $this->collectValueIds($map['rules']);
        $values = $this->loadValues($valueIds);
        $links = $this->loadLinks($valueIds);
        $productCategories = $this->loadProductCategories(array_keys(array_replace([], ...array_values($links))));
        $scopeCategoryIds = [];
        foreach ($map['rules'] as $rule) {
            $scopeCategoryIds = array_merge($scopeCategoryIds, array_keys($rule['categories'] ?? []));
        }
        $categoryUrls = $this->loadCategoryUrls(array_merge($scopeCategoryIds, ...array_values($productCategories)));
        $aliasCounts = $this->countAliases($valueIds);

        $exists = [];
        foreach ($valueIds as $id) {
            if (!isset($values[$id])) {
                throw new \InvalidArgumentException("Значення #{$id} немає.");
            }
            if ($values[$id]['feature_id'] !== $map['feature_id']) {
                throw new \InvalidArgumentException("Значення #{$id} належить іншій властивості (#{$values[$id]['feature_id']}).");
            }
            $exists[$id] = true;
        }

        $steps = [];
        foreach ($map['rules'] as $number => $rule) {
            $label = 'Правило ' . ($number + 1);
            foreach ($rule['expected'] as $id => $text) {
                if (empty($exists[$id])) {
                    throw new \InvalidArgumentException("{$label}: значення #{$id} видалено попереднім правилом.");
                }
                if ($values[$id]['value'] !== $text) {
                    throw new \InvalidArgumentException("{$label}: значення #{$id} має текст «{$values[$id]['value']}», а не «{$text}».");
                }
            }

            if ($rule['type'] === 'rename') {
                $steps[] = $this->planRename($label, $rule, $values, $languages, $langIdsByLabel);
                continue;
            }

            foreach ($rule['categories'] as $categoryId => $url) {
                if (($categoryUrls[$categoryId] ?? null) !== $url) {
                    throw new \InvalidArgumentException("{$label}: категорії #{$categoryId} з адресою «{$url}» немає.");
                }
            }
            $steps[] = $this->planMerge($rule, $values, $links, $exists, $productCategories, $categoryUrls, $aliasCounts, $languages);
        }

        $expected = [];
        foreach ($valueIds as $id) {
            $products = array_keys($links[$id] ?? []);
            sort($products);
            $expected[$id] = [
                'exists' => !empty($exists[$id]),
                'value' => $values[$id]['value'],
                'translit' => $values[$id]['translit'],
                'texts' => $values[$id]['texts'],
                'products' => $products,
            ];
        }

        $plan = [
            'feature' => ['id' => (int)$feature->id, 'name' => (string)$feature->name, 'url' => (string)$feature->url],
            'languages' => $languages,
            'steps' => $steps,
            'redirects' => $map['redirects'],
            'expected' => $expected,
        ];

        return ExtenderFacade::execute(__METHOD__, $plan, func_get_args());
    }

    /**
     * Виконує карту в одній транзакції; у чужій транзакції — через SAVEPOINT.
     *
     * @param array<string, mixed> $map розібраний JSON карти
     * @return array<string, mixed> виконаний план, як його повертає plan()
     * @throws \InvalidArgumentException карта не відповідає даним, нічого не змінено
     * @throws \RuntimeException запис не вдався або стан розійшовся з планом, усе відкочено
     */
    public function apply(array $map)
    {
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }
        $langId = $this->languages->getLangId();

        try {
            $plan = $this->plan($map);

            foreach ($plan['steps'] as $step) {
                if ($step['type'] === 'merge') {
                    $this->mergeValues(
                        $step['target']['id'],
                        array_column($step['sources'], 'id'),
                        array_keys($step['categories'])
                    );
                } else {
                    $this->renameValue($step['value']['id'], array_map(static fn (array $t): string => $t['to'], $step['texts']));
                }
            }

            if ($plan['redirects'] !== []) {
                $stored = $this->storeRedirects($plan['redirects']);
                if ($stored !== count($plan['redirects'])) {
                    throw new \RuntimeException(sprintf(
                        'Збережено %d redirect-правил із %d: модуль редиректів не ввімкнено або він їх відхилив.',
                        $stored,
                        count($plan['redirects'])
                    ));
                }
            }

            $this->verify($plan['expected']);

            if ($ownTransaction) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }
        } catch (\Throwable $e) {
            if ($ownTransaction) {
                $this->pdo->rollBack();
            } else {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $this->pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }
            throw $e;
        } finally {
            if ($langId !== null) {
                $this->languages->setLangId($langId);
            }
        }

        return ExtenderFacade::execute(__METHOD__, $plan, func_get_args());
    }

    /**
     * Переносить товари джерел на ціль; без категорій ще й видаляє джерела.
     * Точка розширення для модулів, що зберігають id значень у своїх таблицях.
     *
     * @param int[] $sourceIds
     * @param int[] $categoryIds порожньо — усі товари джерел
     * @throws \RuntimeException запит не виконався
     */
    public function mergeValues(int $targetId, array $sourceIds, array $categoryIds)
    {
        $scope = $categoryIds === []
            ? ''
            : ' AND product_id IN (SELECT pc.product_id FROM __products_categories pc WHERE pc.category_id IN (:category_ids))';

        // IGNORE відкидає товари, що вже мають ціль: унікальний ключ (product_id, value_id).
        $insert = $this->queryFactory->newSqlQuery();
        $insert->setStatement(
            'INSERT IGNORE INTO __products_features_values (product_id, value_id)
             SELECT DISTINCT product_id, :target_id FROM __products_features_values
             WHERE value_id IN (:source_ids)' . $scope
        )->bindValue('target_id', $targetId)->bindValue('source_ids', $sourceIds);

        $delete = $this->queryFactory->newSqlQuery();
        $delete->setStatement('DELETE FROM __products_features_values WHERE value_id IN (:source_ids)' . $scope)
            ->bindValue('source_ids', $sourceIds);

        if ($categoryIds !== []) {
            $insert->bindValue('category_ids', $categoryIds);
            $delete->bindValue('category_ids', $categoryIds);
        }

        if (!$this->db->query($insert) || !$this->db->query($delete)) {
            throw new \RuntimeException('Не вдалося перенести товари на значення #' . $targetId . ', подробиці в журналі.');
        }

        if ($categoryIds === []) {
            $this->featuresValuesEntity->delete($sourceIds);
        }

        ExtenderFacade::execute(__METHOD__, null, func_get_args());
    }

    /**
     * Міняє текст значення в указаних мовах; translit лишається.
     *
     * @param array<int, string> $textsByLangId
     * @throws \RuntimeException запит не виконався
     */
    public function renameValue(int $valueId, array $textsByLangId)
    {
        foreach ($textsByLangId as $langId => $text) {
            $this->languages->setLangId($langId);
            if (!$this->featuresValuesEntity->update($valueId, ['value' => $text])) {
                throw new \RuntimeException("Не вдалося перейменувати значення #{$valueId}, подробиці в журналі.");
            }
        }

        ExtenderFacade::execute(__METHOD__, null, func_get_args());
    }

    /**
     * Точка розширення для модуля редиректів: ядро їх не зберігає й повертає 0.
     * Обробник повертає накопичену кількість збережених правил.
     *
     * @param list<array{from: string, to: string}> $redirects шляхи сайту без початкового «/»
     * @return int
     */
    public function storeRedirects(array $redirects)
    {
        return (int)ExtenderFacade::execute(__METHOD__, 0, func_get_args());
    }

    /**
     * @return array{feature_id: int, rules: list<array<string, mixed>>, redirects: list<array{from: string, to: string}>}
     */
    private function normalizeMap(array $map): array
    {
        $featureId = $map['feature_id'] ?? null;
        if (!is_int($featureId) || $featureId <= 0) {
            throw new \InvalidArgumentException('У карті немає feature_id.');
        }
        if (empty($map['rules']) || !is_array($map['rules']) || !array_is_list($map['rules'])) {
            throw new \InvalidArgumentException('У карті немає правил (rules).');
        }

        $rules = [];
        foreach ($map['rules'] as $number => $rule) {
            $label = 'Правило ' . ($number + 1);
            if (!is_array($rule)) {
                throw new \InvalidArgumentException("{$label}: очікується об'єкт.");
            }
            $note = isset($rule['note']) ? (string)$rule['note'] : '';

            if (isset($rule['rename'])) {
                $value = $this->normalizeValueRef($rule['rename'], $label);
                if (empty($rule['to']) || !is_array($rule['to'])) {
                    throw new \InvalidArgumentException("{$label}: немає нових текстів (to).");
                }
                $texts = [];
                foreach ($rule['to'] as $langLabel => $text) {
                    $text = is_string($text) ? trim($text) : '';
                    if ($text === '') {
                        throw new \InvalidArgumentException("{$label}: порожній текст для мови «{$langLabel}».");
                    }
                    $texts[(string)$langLabel] = $text;
                }
                $rules[] = ['type' => 'rename', 'note' => $note, 'id' => $value['id'], 'texts' => $texts,
                    'expected' => [$value['id'] => $value['value']]];
                continue;
            }

            if (empty($rule['merge']) || !is_array($rule['merge']) || !isset($rule['into'])) {
                throw new \InvalidArgumentException("{$label}: потрібні merge та into або rename та to.");
            }
            $target = $this->normalizeValueRef($rule['into'], $label);
            $expected = [$target['id'] => $target['value']];
            $sources = [];
            foreach ($rule['merge'] as $source) {
                $source = $this->normalizeValueRef($source, $label);
                if ($source['id'] === $target['id'] || isset($expected[$source['id']])) {
                    throw new \InvalidArgumentException("{$label}: значення #{$source['id']} повторюється або збігається з ціллю.");
                }
                $expected[$source['id']] = $source['value'];
                $sources[] = $source['id'];
            }

            $categories = [];
            foreach ((array)($rule['categories'] ?? []) as $category) {
                $categoryId = is_array($category) ? ($category['id'] ?? null) : null;
                $url = is_array($category) ? ($category['url'] ?? null) : null;
                if (!is_int($categoryId) || $categoryId <= 0 || !is_string($url) || $url === '') {
                    throw new \InvalidArgumentException("{$label}: категорія задається як {\"id\": …, \"url\": …}.");
                }
                $categories[$categoryId] = $url;
            }

            $rules[] = ['type' => 'merge', 'note' => $note, 'target' => $target['id'], 'sources' => $sources,
                'categories' => $categories, 'expected' => $expected];
        }

        $redirects = [];
        foreach ((array)($map['redirects'] ?? []) as $redirect) {
            $from = $this->normalizePath(is_array($redirect) ? ($redirect['from'] ?? null) : null);
            $to = $this->normalizePath(is_array($redirect) ? ($redirect['to'] ?? null) : null);
            if ($from === $to || isset($redirects[$from])) {
                throw new \InvalidArgumentException("Редирект «{$from}» повторюється або веде сам на себе.");
            }
            $redirects[$from] = ['from' => $from, 'to' => $to];
        }

        return ['feature_id' => $featureId, 'rules' => $rules, 'redirects' => array_values($redirects)];
    }

    /** @return array{id: int, value: string} */
    private function normalizeValueRef($ref, string $label): array
    {
        $id = is_array($ref) ? ($ref['id'] ?? null) : null;
        $value = is_array($ref) ? ($ref['value'] ?? null) : null;
        if (!is_int($id) || $id <= 0 || !is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("{$label}: значення задається як {\"id\": …, \"value\": \"поточний текст\"}.");
        }

        return ['id' => $id, 'value' => trim($value)];
    }

    /** Шлях сайту без домену: адреса з доменом чи протоколом вела б редирект за межі сайту. */
    private function normalizePath($path): string
    {
        $path = is_string($path) ? trim($path) : '';
        if ($path === '' || str_contains($path, '://') || str_starts_with($path, '//')) {
            throw new \InvalidArgumentException("Редирект: «{$path}» — не шлях сайту.");
        }

        $path = ltrim($path, '/');
        if ($path === '') {
            throw new \InvalidArgumentException('Редирект на головну чи з головної не підтримується.');
        }

        return $path;
    }

    /** @return int[] */
    private function collectValueIds(array $rules): array
    {
        $ids = [];
        foreach ($rules as $rule) {
            $ids = array_merge($ids, array_keys($rule['expected']));
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param int[] $ids
     * @return array<int, array{feature_id: int, value: string, translit: string, texts: array<int, array{value: string, translit: string}>}>
     */
    private function loadValues(array $ids): array
    {
        $select = $this->queryFactory->newSelect();
        $select->from('__features_values')->cols(['id', 'feature_id', 'value', 'translit'])
            ->where('id IN (:ids)')->bindValue('ids', $ids);
        $values = [];
        foreach ($this->fetch($select) as $row) {
            $values[(int)$row->id] = [
                'feature_id' => (int)$row->feature_id,
                'value' => trim((string)$row->value),
                'translit' => (string)$row->translit,
                'texts' => [],
            ];
        }

        $select = $this->queryFactory->newSelect();
        $select->from('__lang_features_values')->cols(['feature_value_id', 'lang_id', 'value', 'translit'])
            ->where('feature_value_id IN (:ids)')->bindValue('ids', $ids)->orderBy(['lang_id']);
        foreach ($this->fetch($select) as $row) {
            if (isset($values[(int)$row->feature_value_id])) {
                $values[(int)$row->feature_value_id]['texts'][(int)$row->lang_id] = [
                    'value' => (string)$row->value,
                    'translit' => (string)$row->translit,
                ];
            }
        }

        return $values;
    }

    /**
     * @param int[] $valueIds
     * @return array<int, array<int, true>> value_id => product_id => true
     */
    private function loadLinks(array $valueIds): array
    {
        $select = $this->queryFactory->newSelect();
        $select->from('__products_features_values')->cols(['product_id', 'value_id'])
            ->where('value_id IN (:ids)')->bindValue('ids', $valueIds);
        $links = array_fill_keys($valueIds, []);
        foreach ($this->fetch($select) as $row) {
            $links[(int)$row->value_id][(int)$row->product_id] = true;
        }

        return $links;
    }

    /**
     * @param int[] $productIds
     * @return array<int, int[]>
     */
    private function loadProductCategories(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $select = $this->queryFactory->newSelect();
        $select->from('__products_categories')->cols(['product_id', 'category_id'])
            ->where('product_id IN (:ids)')->bindValue('ids', $productIds);
        $categories = [];
        foreach ($this->fetch($select) as $row) {
            $categories[(int)$row->product_id][] = (int)$row->category_id;
        }

        return $categories;
    }

    /**
     * @param int[] $categoryIds
     * @return array<int, string>
     */
    private function loadCategoryUrls(array $categoryIds): array
    {
        $categoryIds = array_values(array_unique($categoryIds));
        if ($categoryIds === []) {
            return [];
        }
        $select = $this->queryFactory->newSelect();
        $select->from('__categories')->cols(['id', 'url'])->where('id IN (:ids)')->bindValue('ids', $categoryIds);
        $urls = [];
        foreach ($this->fetch($select) as $row) {
            $urls[(int)$row->id] = (string)$row->url;
        }

        return $urls;
    }

    /**
     * @param int[] $valueIds
     * @return array<int, int>
     */
    private function countAliases(array $valueIds): array
    {
        $select = $this->queryFactory->newSelect();
        $select->from('__features_values_aliases_values')->cols(['feature_value_id', 'COUNT(*) AS n'])
            ->where('feature_value_id IN (:ids)')->bindValue('ids', $valueIds)->groupBy(['feature_value_id']);
        $counts = [];
        foreach ($this->fetch($select) as $row) {
            $counts[(int)$row->feature_value_id] = (int)$row->n;
        }

        return $counts;
    }

    /** @return object[] */
    private function fetch($select): array
    {
        if (!$this->db->query($select)) {
            throw new \RuntimeException('Не вдалося прочитати дані значень, подробиці в журналі.');
        }

        return (array)$this->db->results();
    }

    /**
     * Симулює злиття на завантажених зв'язках і змінює їх так, як це зробить mergeValues().
     *
     * @return array<string, mixed>
     */
    private function planMerge(
        array $rule,
        array $values,
        array &$links,
        array &$exists,
        array $productCategories,
        array $categoryUrls,
        array $aliasCounts,
        array $languages
    ): array {
        $targetId = $rule['target'];
        $scope = $rule['categories'];
        $inScope = static fn (int $productId): bool => $scope === []
            || array_intersect($productCategories[$productId] ?? [], array_keys($scope)) !== [];

        $describe = static fn (int $id) => [
            'id' => $id,
            'value' => $values[$id]['value'],
            'texts' => $values[$id]['texts'],
            'products' => count($links[$id]),
        ];

        $target = $describe($targetId);
        $sources = [];
        $moved = [];
        $rowsDeleted = 0;
        foreach ($rule['sources'] as $sourceId) {
            $source = $describe($sourceId);
            $movedHere = array_filter($links[$sourceId], $inScope, ARRAY_FILTER_USE_KEY);
            $source['moved'] = count($movedHere);
            $source['aliases'] = $aliasCounts[$sourceId] ?? 0;
            $sources[] = $source;
            $rowsDeleted += count($movedHere);
            $moved += $movedHere;
            $links[$sourceId] = array_diff_key($links[$sourceId], $movedHere);
        }

        $alreadyHadTarget = count(array_intersect_key($moved, $links[$targetId]));
        $links[$targetId] += $moved;

        $byCategory = [];
        $outsideScope = [];
        foreach (array_keys($moved) as $productId) {
            foreach ($productCategories[$productId] ?? [] as $categoryId) {
                $url = $categoryUrls[$categoryId] ?? ('#' . $categoryId);
                $byCategory[$url] = ($byCategory[$url] ?? 0) + 1;
                if ($scope !== [] && !isset($scope[$categoryId])) {
                    $outsideScope[$url] = ($outsideScope[$url] ?? 0) + 1;
                }
            }
        }
        arsort($byCategory);
        arsort($outsideScope);

        $leftInSource = [];
        foreach ($rule['sources'] as $sourceId) {
            if ($scope === []) {
                $exists[$sourceId] = false;
                continue;
            }
            $left = [];
            foreach (array_keys($links[$sourceId]) as $productId) {
                foreach ($productCategories[$productId] ?? [] as $categoryId) {
                    $url = $categoryUrls[$categoryId] ?? ('#' . $categoryId);
                    $left[$url] = ($left[$url] ?? 0) + 1;
                }
            }
            arsort($left);
            $leftInSource[$sourceId] = ['count' => count($links[$sourceId]), 'categories' => $left];
        }

        $urlChanges = [];
        foreach ($languages as $langId => $language) {
            $to = $values[$targetId]['texts'][$langId]['translit'] ?? $values[$targetId]['translit'];
            foreach ($rule['sources'] as $sourceId) {
                $from = $values[$sourceId]['texts'][$langId]['translit'] ?? $values[$sourceId]['translit'];
                $change = ['lang' => $language['label'], 'from' => $from, 'to' => $to];
                if ($from !== $to && !in_array($change, $urlChanges, true)) {
                    $urlChanges[] = $change;
                }
            }
        }

        return [
            'type' => 'merge',
            'note' => $rule['note'],
            'target' => $target,
            'sources' => $sources,
            'categories' => $scope,
            'products_moved' => count($moved),
            'already_had_target' => $alreadyHadTarget,
            'rows_deleted' => $rowsDeleted,
            'rows_added' => count($moved) - $alreadyHadTarget,
            'moved_by_category' => $byCategory,
            'outside_scope' => $outsideScope,
            'left_in_source' => $leftInSource,
            'deletes_sources' => $scope === [],
            'target_products_after' => count($links[$targetId]),
            'url_changes' => $urlChanges,
        ];
    }

    /** @return array<string, mixed> */
    private function planRename(string $label, array $rule, array &$values, array $languages, array $langIdsByLabel): array
    {
        $id = $rule['id'];
        $mainLangId = null;
        foreach ($languages as $langId => $language) {
            if ($language['main']) {
                $mainLangId = $langId;
            }
        }
        // FeaturesValuesEntity::update() пише в основну таблицю translit основної мови;
        // якщо вони розходяться, перейменування мовчки змінило б адресу.
        if ($mainLangId !== null && isset($values[$id]['texts'][$mainLangId])
            && $values[$id]['texts'][$mainLangId]['translit'] !== $values[$id]['translit']) {
            throw new \InvalidArgumentException("{$label}: у значення #{$id} translit основної таблиці й основної мови різні, перейменуйте його в адмінці.");
        }

        $texts = [];
        foreach ($rule['texts'] as $langLabel => $text) {
            if (!isset($langIdsByLabel[$langLabel])) {
                throw new \InvalidArgumentException("{$label}: мови «{$langLabel}» немає.");
            }
            $langId = $langIdsByLabel[$langLabel];
            $current = $values[$id]['texts'][$langId] ?? ['value' => '', 'translit' => $values[$id]['translit']];
            $texts[$langId] = ['lang' => $langLabel, 'from' => $current['value'], 'to' => $text, 'translit' => $current['translit']];
            $values[$id]['texts'][$langId] = ['value' => $text, 'translit' => $current['translit']];
            if ($langId === $mainLangId) {
                $values[$id]['value'] = $text;
            }
        }

        return [
            'type' => 'rename',
            'note' => $rule['note'],
            'value' => ['id' => $id, 'value' => $rule['expected'][$id]],
            'texts' => $texts,
        ];
    }

    /**
     * Звіряє стан бази з очікуваним планом.
     *
     * @throws \RuntimeException
     */
    private function verify(array $expected): void
    {
        $ids = array_keys($expected);
        $values = $this->loadValues($ids);
        $links = $this->loadLinks($ids);
        $aliases = $this->countAliases($ids);

        foreach ($expected as $id => $state) {
            $products = array_keys($links[$id]);
            sort($products);
            if (!$state['exists']) {
                $found = isset($values[$id]) || $products !== [] || !empty($aliases[$id]) || $this->hasLangRows($id);
                if ($found) {
                    throw new \RuntimeException("Значення #{$id} мало бути видалене разом із мовними рядками й зв'язками.");
                }
                continue;
            }

            $actual = $values[$id] ?? null;
            if ($actual === null
                || $actual['value'] !== trim($state['value'])
                || $actual['translit'] !== $state['translit']
                || $actual['texts'] !== $state['texts']
                || $products !== $state['products']) {
                throw new \RuntimeException("Стан значення #{$id} після запису розійшовся з планом.");
            }
        }
    }

    private function hasLangRows(int $id): bool
    {
        $select = $this->queryFactory->newSelect();
        $select->from('__lang_features_values')->cols(['COUNT(*) AS n'])
            ->where('feature_value_id = :id')->bindValue('id', $id);
        if (!$this->db->query($select)) {
            throw new \RuntimeException('Не вдалося перевірити мовні рядки значення #' . $id . '.');
        }

        return (int)$this->db->result('n') > 0;
    }
}
