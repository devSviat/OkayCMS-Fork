<?php


namespace Okay\Helpers;


use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Core\Settings;

class CanonicalHelper
{
    private $settings;

    private $catalogPagination;
    private $catalogPageAll;
    private $catalogBrand;
    private $catalogFeatures;
    private $catalogOtherFilter;
    private $catalogFilterPagination;

    private $maxBrandFilterDepth;
    private $maxOtherFilterDepth;
    private $maxFeaturesFilterDepth;
    private $maxFeaturesValuesFilterDepth;
    private $maxFilterDepth;

    /** 0 — як для категорії з брендом */
    private $allProductsBrand;
    
    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    private function pageAllIsOff(): bool
    {
        return okay_page_all_max_items($this->settings->get('catalog_page_all_max_items')) === PAGE_ALL_OFF;
    }

    public function setParams(
        $catalogPagination,
        $catalogPageAll,
        $catalogBrand,
        $catalogFeatures,
        $catalogOtherFilter,
        $catalogFilterPagination,
        $maxBrandFilterDepth,
        $maxOtherFilterDepth,
        $maxFeaturesFilterDepth,
        $maxFeaturesValuesFilterDepth,
        $maxFilterDepth,
        $allProductsBrand = null
    ) {
        $this->catalogPagination = (int)$catalogPagination;
        $this->catalogPageAll = (int)$catalogPageAll;
        $this->catalogBrand = (int)$catalogBrand;
        $this->catalogFeatures = (int)$catalogFeatures;
        $this->catalogOtherFilter = (int)$catalogOtherFilter;
        $this->catalogFilterPagination = (int)$catalogFilterPagination;

        $this->maxBrandFilterDepth = (int)$maxBrandFilterDepth;
        $this->maxOtherFilterDepth = (int)$maxOtherFilterDepth;
        $this->maxFeaturesFilterDepth = (int)$maxFeaturesFilterDepth;
        $this->maxFeaturesValuesFilterDepth = (int)$maxFeaturesValuesFilterDepth;
        $this->maxFilterDepth = (int)$maxFilterDepth;

        $this->allProductsBrand = (int)$allProductsBrand;
    }
    
    /**
     * Дописує до адреси каталогу хвіст із filterChpuUrl().
     * Хвіст може починатись одразу з `?keyword=`: слеш перед ним дає адресу,
     * яка 301-редиректить назад, і canonical указував би на редирект.
     */
    public function appendFilterUrl(string $baseUrl, string $filterUrl): string
    {
        $filterUrl = ltrim($filterUrl, '/');
        $result = $baseUrl;
        if ($filterUrl !== '') {
            $result = rtrim($baseUrl, '/') . (str_starts_with($filterUrl, '?') ? '' : '/') . $filterUrl;
        }

        return ExtenderFacade::execute(__METHOD__, $result, func_get_args());
    }

    /**
     * @param string|int $page текущая страница, может быть all
     * @param array $otherFilter одномерный массив, содержащий значения ['discounted', 'featured'...]
     * @param array $featuresFilter
     * @param array $brandsFilter
     * @return array|false
     * 
     * Определение canonical для категории
     */
    public function getCatalogCanonicalData($page, array $otherFilter, array $featuresFilter, array $brandsFilter)
    {
        return $this->canonicalData($page, $otherFilter, $featuresFilter, $brandsFilter, $this->catalogBrand); // no ExtenderFacade
    }

    /**
     * Canonical сторінки всіх товарів на її ж маршруті: як getCatalogCanonicalData(),
     * але фільтр за брендом підпорядковується canonical_all_products_brand. Незадане
     * значення і CANONICAL_BRAND_PAGE беруть правило canonical_category_brand: адреси,
     * які зводяться до сторінки бренду, віддає getAllProductsBrandPageCanonicalData().
     *
     * @param string|int $page поточна сторінка, може бути all
     * @return array|false
     */
    public function getAllProductsCanonicalData($page, array $otherFilter, array $featuresFilter, array $brandsFilter)
    {
        $brandCanonical = $this->allProductsBrand;
        if ($brandCanonical === 0 || $brandCanonical === CANONICAL_BRAND_PAGE) {
            $brandCanonical = $this->catalogBrand;
        }

        $result = $this->canonicalData($page, $otherFilter, $featuresFilter, $brandsFilter, $brandCanonical);

        return ExtenderFacade::execute(__METHOD__, $result, func_get_args());
    }

    /**
     * Дані для filterChpuUrl() на маршруті brand, коли canonical_all_products_brand
     * дорівнює CANONICAL_BRAND_PAGE, а адреса /all-products — це один бренд без інших
     * фільтрів (ціна переноситься, сортування відкидається). Пагінація підпорядковується
     * тим самим налаштуванням, що й фільтр «на себе»; якщо вони прибирають бренд чи
     * canonical, повертає null, і діє getAllProductsCanonicalData().
     *
     * @param string|int $page поточна сторінка, може бути all
     * @return array|null null — canonical лишається на /all-products
     */
    public function getAllProductsBrandPageCanonicalData($page, array $otherFilter, array $featuresFilter, array $brandsFilter): ?array
    {
        $result = null;

        if ($this->allProductsBrand === CANONICAL_BRAND_PAGE
            && count($brandsFilter) === 1
            && empty($otherFilter)
            && empty($featuresFilter)
        ) {
            $data = $this->canonicalData($page, [], [], $brandsFilter, CANONICAL_WITH_FILTER);
            if (is_array($data) && !array_key_exists('brand', $data)) {
                // Бренд на маршруті brand уже в самій адресі, а не у хвості фільтра.
                $result = ['brand' => null] + $data;
            }
        }

        return ExtenderFacade::execute(__METHOD__, $result, func_get_args());
    }

    /**
     * Canonical посадкової «бренд + значення властивості» (BrandFeatureLandingsHelper::isLanding()):
     * на себе без сортування; сторінка — за canonical_catalog_filter_pagination, як у фільтрів.
     * Глибина фільтра й правила бренду/властивостей тут не діють.
     *
     * @param string|int $page поточна сторінка, може бути all
     * @param array $featuresFilter ключі — url властивостей
     * @return array|false
     */
    public function getBrandFeatureLandingCanonicalData($page, array $featuresFilter, array $brandsFilter)
    {
        $result = ['sort' => null];
        if ($page == 'all' && $this->pageAllIsOff()) {
            $result = ['page' => null] + $result;
        } elseif (!empty($page)) {
            $pagination = $this->filterPaginationCanonicalData($page, $featuresFilter, $brandsFilter);
            $result = $pagination === false ? false : $pagination + $result;
        }

        return ExtenderFacade::execute(__METHOD__, $result, func_get_args());
    }

    /**
     * @param string|int $page
     * @return array|false
     */
    private function canonicalData($page, array $otherFilter, array $featuresFilter, array $brandsFilter, ?int $brandCanonical)
    {
        // Вимкнений page-all віддає звичайну першу сторінку, тож канонікал
        // рахується так, наче її й запитали: інакше кожне налаштування, яке вміє
        // вказати на page-all чи лишити поточну адресу, склеїло б каталог на
        // дубль першої сторінки.
        $servesFirstPage = $page == 'all' && $this->pageAllIsOff();
        if ($servesFirstPage) {
            $page = '';
        }

        $result = $this->catalogCanonicalData($page, $otherFilter, $featuresFilter, $brandsFilter, $brandCanonical);

        // Адресу треба ще й явно занулити: без ключа 'page' будівник URL лишає
        // поточний сегмент, і канонікал стає самопосиланням на page-all.
        if ($servesFirstPage && is_array($result)) {
            $result['page'] = null;
        }

        return $result; // no ExtenderFacade
    }

    /**
     * @param string|int $page
     * @param array $otherFilter
     * @param array $featuresFilter
     * @param array $brandsFilter
     * @return array|false
     */
    private function catalogCanonicalData($page, array $otherFilter, array $featuresFilter, array $brandsFilter, ?int $brandCanonical)
    {
        $result = [];

        // Подсчитываем общую глубину фильтра
        $filterDepth = 0;
        if (!empty($otherFilter)) {
            $result['filter'] = null;
            $filterDepth++;
        }
        if (!empty($featuresFilter)) {
            $filterDepth += count($featuresFilter);
            $result = array_merge($result,
                array_fill_keys(array_keys($featuresFilter), null)
            );
        }
        if (!empty($brandsFilter)) {
            $result['brand'] = null;
            $filterDepth++;
        }

        if ($filterDepth > $this->maxFilterDepth) {
            if (!empty($page)) {
                $result['page'] = null;
            }
            $result['sort'] = null;
            return $result; // no ExtenderFacade
        }

        // Т.к. getCatalogCanonicalDataExecutor не может вернуть связку для доп. фильтров и свойств или бренда,
        // определяем эту связку здесь
        if (!empty($otherFilter)) {
            if (count($otherFilter) > $this->maxOtherFilterDepth) {
                if (!empty($page)) {
                    $result['page'] = null;
                }
                $result['sort'] = null;
                $result['filter'] = null;
                return $result; // no ExtenderFacade
            }
        }

        if (($baseCatalogData = $this->getBaseCatalogCanonicalData($page, $otherFilter)) === false) {
            return false; // no ExtenderFacade
        }

        if (($catalogData = $this->getCatalogCanonicalDataExecutor($page, $featuresFilter, $brandsFilter, $brandCanonical)) === false) {
            return false; // no ExtenderFacade
        }

        return $catalogData + $baseCatalogData; // no ExtenderFacade
    }
    
    private function getCatalogCanonicalDataExecutor($page, array $featuresFilter, array $brandsFilter, ?int $brandCanonical = null)
    {
        $result = [];

        // Определяем не превысили ли максимальное кол-во свойств или значений одного свойства
        if (!empty($featuresFilter)) {
            if (count($featuresFilter) > $this->maxFeaturesFilterDepth) {
                $result = array_merge($result,
                    array_fill_keys(array_keys($featuresFilter), null)
                );
                if (!empty($brandsFilter)) {
                    $result['brand'] = null;
                }
            } else {
                foreach ($featuresFilter as $values) {
                    if (count($values) > $this->maxFeaturesValuesFilterDepth) {
                        $result = array_merge($result,
                            array_fill_keys(array_keys($featuresFilter), null)
                        );
                        if (!empty($brandsFilter)) {
                            $result['brand'] = null;
                        }
                        break;
                    }
                }
            }
        }

        // Не превысили ли максимальное кол-во брендов
        if (!empty($brandsFilter)) {
            if (count($brandsFilter) > $this->maxBrandFilterDepth) {
                $result['brand'] = null;
            }
        }

        if (!empty($result)) {
            if (!empty($page)) {
                $result['page'] = null;
            }
            return $result; // no ExtenderFacade
        }

        if (!empty($page) && (!empty($featuresFilter) || !empty($brandsFilter))) {
            return $this->filterPaginationCanonicalData($page, $featuresFilter, $brandsFilter); // no ExtenderFacade
        }

        if (!empty($featuresFilter)) {
            switch ($this->catalogFeatures) {
                case CANONICAL_WITHOUT_FILTER:

                    // Заполняем массив, где ключи - транслиты свойств, значение null, чтобы удалить эти значения
                    $result = array_merge($result,
                        array_fill_keys(array_keys($featuresFilter), null)
                    );

                    break;
                case CANONICAL_WITH_FILTER:
                    break;
                case CANONICAL_ABSENT:
                    return false; // no ExtenderFacade
            }
        }

        if (!empty($brandsFilter)) {
            switch ($brandCanonical ?? $this->catalogBrand) {
                case CANONICAL_WITHOUT_FILTER:
                    $result['brand'] = null;
                    break;
                case CANONICAL_WITH_FILTER:
                    break;
                case CANONICAL_ABSENT:
                    return false; // no ExtenderFacade
            }
        }

        return $result; // no ExtenderFacade
    }

    /**
     * canonical_catalog_filter_pagination для непорожньої сторінки фільтра за брендом чи властивістю.
     *
     * @param string|int $page
     * @return array|false
     */
    private function filterPaginationCanonicalData($page, array $featuresFilter, array $brandsFilter)
    {
        $result = [];

        switch ($this->catalogFilterPagination) {
            case CANONICAL_WITHOUT_FILTER_FIRST_PAGE:
                $result['page'] = null;

                if (!empty($featuresFilter)) {
                    // Заполняем массив, где ключи - транслиты свойств, значение null, чтобы удалить эти значения
                    $result = array_merge($result,
                        array_fill_keys(array_keys($featuresFilter), null)
                    );
                }
                $result['brand'] = null;
                break;
            case CANONICAL_FIRST_PAGE:
                $result['page'] = null;
                break;
            case CANONICAL_CURRENT_PAGE:
                $result['page'] = $page;
                break;
            case CANONICAL_ABSENT:
                return false; // no ExtenderFacade
        }

        return $result; // no ExtenderFacade
    }

    /**
     * @param string|int $page текущая страница, может быть all
     * @param array $otherFilter одномерный массив, содержащий значения ['discounted', 'featured'...]
     * @return array|false
     *
     * Определение canonical для страниц списков товаров (discounted, all-products, brand)
     */
    private function getBaseCatalogCanonicalData($page, array $otherFilter)
    {
        $result = [
            'sort' => null,
        ];

        if ($this->maxFilterDepth === 0 && !empty($otherFilter)) {
            $result['filter'] = null;
            return $result; // no ExtenderFacade
        }

        if (!empty($otherFilter)) {
            if (count($otherFilter) > $this->maxOtherFilterDepth) {
                $result['filter'] = null;
                return $result; // no ExtenderFacade
            }
        }

        if (!empty($page) && !empty($otherFilter)) {
            switch ($this->catalogFilterPagination) {
                case CANONICAL_WITHOUT_FILTER_FIRST_PAGE:
                    $result['page'] = null;
                    $result['filter'] = null;
                    break;
                case CANONICAL_FIRST_PAGE:
                    $result['page'] = null;
                    break;
                case CANONICAL_CURRENT_PAGE:
                    $result['page'] = $page;
                    break;
                case CANONICAL_ABSENT:
                    return false; // no ExtenderFacade
            }
            return $result; // no ExtenderFacade
        }

        if (!empty($page)) {
            if ($page == 'all') {
                switch ($this->catalogPageAll) {
                    case CANONICAL_FIRST_PAGE:
                        $result['page'] = null;
                        break;
                    case CANONICAL_CURRENT_PAGE:
                        $result['page'] = 'all';
                        break;
                    case CANONICAL_ABSENT:
                        return false; // no ExtenderFacade
                }
            } else {
                switch ($this->catalogPagination) {
                    case CANONICAL_FIRST_PAGE:
                        $result['page'] = null;
                        break;
                    case CANONICAL_CURRENT_PAGE:
                        $result['page'] = $page;
                        break;
                    case CANONICAL_PAGE_ALL:
                        // Склеювати пагінацію на адресу, якої більше немає,
                        // нема куди - лишається перша сторінка.
                        $result['page'] = $this->pageAllIsOff() ? null : 'all';
                        break;
                    case CANONICAL_ABSENT:
                        return false; // no ExtenderFacade
                }
            }
        }

        if (!empty($otherFilter)) {
            switch ($this->catalogOtherFilter) {
                case CANONICAL_WITHOUT_FILTER:
                    $result['filter'] = null;
                    break;
                case CANONICAL_WITH_FILTER:
                    break;
                case CANONICAL_ABSENT:
                    return false; // no ExtenderFacade
            }
        }

        return $result; // no ExtenderFacade
    }
}