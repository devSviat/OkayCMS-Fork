<?php

namespace Modules\OkayCMS\FAQ;

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Okay\Core\Languages;
use Okay\Modules\OkayCMS\FAQ\Entities\FAQEntity;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Без $searchFields фільтр keyword будує WHERE () — MySQL відповідає синтаксичною
 * помилкою, а адмінка ковтає її й показує порожній список. Шукати треба в мовній
 * таблиці (`l`): список в адмінці показує текст поточної мови, а основна таблиця
 * тримає копію лише однієї.
 */
class FAQKeywordSearchTest extends TestCase
{
    public function testKeywordSearchesQuestionAndAnswerOfCurrentLanguage(): void
    {
        [$sql, $bind] = self::keywordSql('доставка оплата');

        self::assertStringNotContainsString('()', $sql);
        self::assertStringContainsString('(`l`.`question` LIKE :auto_keyword_question_0 OR `l`.`answer` LIKE :auto_keyword_answer_0)', $sql);
        self::assertStringContainsString('(`l`.`question` LIKE :auto_keyword_question_1 OR `l`.`answer` LIKE :auto_keyword_answer_1)', $sql);
        self::assertSame('%доставка%', $bind['auto_keyword_question_0']);
        self::assertSame('%оплата%', $bind['auto_keyword_answer_1']);
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private static function keywordSql(string $keyword): array
    {
        $select = (new AuraQueryFactory('mysql'))->newSelect();
        $select->from('__okaycms__faq__faq AS of_f')->cols(['of_f.id']);

        $languagesClass = new ReflectionClass(Languages::class);
        $languages = $languagesClass->newInstanceWithoutConstructor();
        $languagesClass->getProperty('languagesList')->setValue($languages, [1 => (object)['id' => 1]]);
        $languagesClass->getProperty('langId')->setValue($languages, 1);

        $entity = new ReflectionClass(FAQEntity::class);
        $faq = $entity->newInstanceWithoutConstructor();
        $entity->getProperty('select')->setValue($faq, $select);
        $entity->getProperty('lang')->setValue($faq, $languages);
        $entity->getMethod('filter__keyword')->invoke($faq, $keyword);

        return [(string)$select, $select->getBindValues()];
    }
}
