<?php

namespace Okay\Core\Console\Commands\Features;

use Okay\Admin\Helpers\BackendFeaturesValuesMergeHelper;
use Okay\Core\Console\Command;
use Okay\Core\Modules\Modules;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * Злиття й перейменування значень властивості за JSON-картою. Без --apply лише показує план.
 * Формат карти й порядок дій на робочому сайті — docs/feature-values-merge.md.
 */
#[AsCommand(name: 'features:merge-values', description: 'Merge or rename values of a feature by a JSON map (dry run unless --apply)')]
class FeaturesMergeValuesCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('map', null, InputOption::VALUE_REQUIRED, 'Path to the JSON map')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write the changes in one transaction (default: dry run)');
    }

    protected function handle(Modules $modules, BackendFeaturesValuesMergeHelper $mergeHelper): int
    {
        $path = (string)$this->input->getOption('map');
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            $this->output->writeln('<error>Вкажіть наявний файл карти: --map=шлях/до/карти.json</error>');

            return Command::FAILURE;
        }

        try {
            $map = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->output->writeln('<error>Карта — не JSON: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        if (!is_array($map)) {
            $this->output->writeln('<error>Карта має бути JSON-об\'єктом.</error>');

            return Command::FAILURE;
        }

        // Розширення модулів (редиректи, скидання кешу Redis) реєструються лише в init() модулів.
        $modules->startEnabledModules();

        $apply = (bool)$this->input->getOption('apply');
        try {
            $plan = $apply ? $mergeHelper->apply($map) : $mergeHelper->plan($map);
        } catch (\InvalidArgumentException $e) {
            $this->output->writeln('<error>Карту відхилено, нічого не змінено: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $this->output->writeln('<error>Помилка, усі зміни відкочено: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $this->printPlan($plan);

        if ($apply) {
            $this->output->writeln('ЗАСТОСОВАНО. Скиньте кеш (Redis і compiled/), перевірте сайт — docs/feature-values-merge.md.');
        } else {
            $this->output->writeln('ПРОБНИЙ ПРОГІН: нічого не змінено. Щоб застосувати, додайте --apply.');
        }

        return Command::SUCCESS;
    }

    private function printPlan(array $plan): void
    {
        $out = $this->output;
        $feature = $plan['feature'];
        $labels = [];
        foreach ($plan['languages'] as $language) {
            $labels[] = $language['label'] . ($language['main'] ? ' (основна)' : '');
        }
        $out->writeln("Властивість #{$feature['id']} «{$feature['name']}», адреса фільтра: {$feature['url']}-…; мови: " . implode(', ', $labels));
        $out->writeln('');

        $totals = ['moved' => 0, 'deleted_rows' => 0, 'deleted_values' => 0, 'renamed' => 0];
        foreach ($plan['steps'] as $index => $step) {
            $number = $index + 1;
            if ($step['type'] === 'rename') {
                $totals['renamed']++;
                $out->writeln("Крок {$number}. Перейменування #{$step['value']['id']} «{$step['value']['value']}»" . $this->note($step));
                foreach ($step['texts'] as $text) {
                    $out->writeln("  {$text['lang']}: «{$text['from']}» → «{$text['to']}», translit без змін ({$text['translit']})");
                }
                $out->writeln('');
                continue;
            }

            $scope = $step['categories'] === []
                ? 'усі категорії, джерела видаляються'
                : 'лише категорії ' . $this->categoryList($step['categories']) . ', джерела лишаються';
            $out->writeln("Крок {$number}. Злиття ({$scope})" . $this->note($step));
            $out->writeln('  Ціль:    ' . $this->describeValue($step['target'], $plan['languages'])
                . ", товарів {$step['target']['products']} → {$step['target_products_after']}");
            foreach ($step['sources'] as $source) {
                $aliases = $source['aliases'] > 0 ? ", рядків псевдонімів: {$source['aliases']}" : '';
                $out->writeln('  Джерело: ' . $this->describeValue($source, $plan['languages'])
                    . ", товарів {$source['products']}, переноситься {$source['moved']}{$aliases}");
            }
            $out->writeln("  Товарів перенесено: {$step['products_moved']} (уже мали ціль: {$step['already_had_target']}); "
                . "рядків товар-значення видалено: {$step['rows_deleted']}, додано: {$step['rows_added']}");
            $out->writeln('  Категорії перенесених товарів: ' . $this->counts($step['moved_by_category']));
            if ($step['outside_scope'] !== []) {
                $out->writeln('  Ці товари лежать і поза областю: ' . $this->counts($step['outside_scope']));
            }
            foreach ($step['left_in_source'] as $sourceId => $left) {
                $out->writeln("  Лишається в #{$sourceId}: {$left['count']} товарів (" . $this->counts($left['categories']) . ')');
            }
            if ($step['url_changes'] === []) {
                $out->writeln('  Адреси фільтрів не змінюються (translit однаковий).');
            }
            foreach ($step['url_changes'] as $change) {
                $example = $this->exampleUrl($plan, $step, $change);
                $out->writeln("  Адреса {$change['lang']}: {$feature['url']}-{$change['from']} → {$feature['url']}-{$change['to']}"
                    . ($example !== '' ? "  (напр. {$example})" : ''));
            }
            $out->writeln('');

            $totals['moved'] += $step['products_moved'];
            $totals['deleted_rows'] += $step['rows_deleted'];
            $totals['deleted_values'] += $step['deletes_sources'] ? count($step['sources']) : 0;
        }

        if ($plan['redirects'] === []) {
            $out->writeln('Редиректів у карті немає.');
        } else {
            $out->writeln('Редиректи 301 (зберігає модуль редиректів):');
            foreach ($plan['redirects'] as $redirect) {
                $out->writeln("  /{$redirect['from']} → /{$redirect['to']}");
            }
        }
        $out->writeln('');
        $out->writeln(sprintf(
            'Разом: кроків %d; перенесено товарів %d; видалено рядків товар-значення %d; видалено значень %d; перейменовано %d; редиректів %d.',
            count($plan['steps']),
            $totals['moved'],
            $totals['deleted_rows'],
            $totals['deleted_values'],
            $totals['renamed'],
            count($plan['redirects'])
        ));
    }

    private function note(array $step): string
    {
        return $step['note'] !== '' ? " — {$step['note']}" : '';
    }

    private function describeValue(array $value, array $languages): string
    {
        $texts = [];
        foreach ($value['texts'] as $langId => $text) {
            $label = $languages[$langId]['label'] ?? (string)$langId;
            $texts[] = "{$label}: {$text['value']} / {$text['translit']}";
        }

        return "#{$value['id']} «{$value['value']}» [" . implode('; ', $texts) . ']';
    }

    /** @param array<int, string> $categories */
    private function categoryList(array $categories): string
    {
        $list = [];
        foreach ($categories as $id => $url) {
            $list[] = "{$url} (#{$id})";
        }

        return implode(', ', $list);
    }

    /** @param array<string, int> $counts */
    private function counts(array $counts): string
    {
        if ($counts === []) {
            return '—';
        }
        $list = [];
        foreach ($counts as $name => $count) {
            $list[] = "{$name} {$count}";
        }

        return implode(', ', $list);
    }

    private function exampleUrl(array $plan, array $step, array $change): string
    {
        $category = $step['categories'] !== []
            ? reset($step['categories'])
            : (string)array_key_first($step['moved_by_category']);
        if ($category === '' || $category === false) {
            return '';
        }
        $prefix = '';
        foreach ($plan['languages'] as $language) {
            if ($language['label'] === $change['lang']) {
                $prefix = $language['prefix'];
            }
        }
        $url = $plan['feature']['url'];

        return "/{$prefix}catalog/{$category}/{$url}-{$change['from']} → /{$prefix}catalog/{$category}/{$url}-{$change['to']}";
    }
}
