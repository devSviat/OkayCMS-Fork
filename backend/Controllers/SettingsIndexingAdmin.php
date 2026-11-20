<?php


namespace Okay\Admin\Controllers;


use Giggsey\Locale\Locale;
use libphonenumber\PhoneNumberUtil;
use Okay\Admin\Helpers\BackendSettingsHelper;
use Okay\Core\BackendTranslations;
use Okay\Core\Phone;
use Okay\Helpers\BrandFeatureLandingsHelper;
use Okay\Helpers\MetaRobotsHelper;

class SettingsIndexingAdmin extends IndexAdmin
{
    /** Ті самі значення, що пропонує форма; 0 — як для категорії з брендом. */
    private const array CANONICAL_ALL_PRODUCTS_BRAND = [
        0,
        CANONICAL_BRAND_PAGE,
        CANONICAL_WITHOUT_FILTER,
        CANONICAL_WITH_FILTER,
        CANONICAL_ABSENT,
    ];

    private const array ROBOTS_ALL_PRODUCTS_BRAND = [
        0,
        ROBOTS_INDEX_FOLLOW,
        ROBOTS_NOINDEX_FOLLOW,
        ROBOTS_NOINDEX_NOFOLLOW,
    ];

    public function fetch()
    {
        if ($this->request->method('post')) {
            
            $this->settings->set('canonical_catalog_pagination', 
                $this->request->post('canonical_catalog_pagination', null, CANONICAL_FIRST_PAGE)
            );
            $this->settings->set('canonical_catalog_page_all', 
                $this->request->post('canonical_catalog_page_all', null, CANONICAL_FIRST_PAGE)
            );
            $this->settings->set('canonical_category_brand', 
                $this->request->post('canonical_category_brand', null, CANONICAL_WITHOUT_FILTER)
            );
            $this->settings->set('canonical_all_products_brand',
                self::allowedOrInherit(
                    $this->request->post('canonical_all_products_brand', 'integer'),
                    self::CANONICAL_ALL_PRODUCTS_BRAND
                )
            );
            $this->settings->set('canonical_category_features', 
                $this->request->post('canonical_category_features', null, CANONICAL_WITHOUT_FILTER)
            );
            $this->settings->set('canonical_catalog_other_filter', 
                $this->request->post('canonical_catalog_other_filter', null, CANONICAL_WITHOUT_FILTER)
            );
            $this->settings->set('canonical_catalog_filter_pagination', 
                $this->request->post('canonical_catalog_filter_pagination', null, CANONICAL_WITHOUT_FILTER_FIRST_PAGE)
            );
            
            $this->settings->set('robots_catalog_pagination', 
                $this->request->post('robots_catalog_pagination', null, ROBOTS_INDEX_FOLLOW)
            );
            $this->settings->set('robots_catalog_page_all', 
                $this->request->post('robots_catalog_page_all', null, ROBOTS_INDEX_FOLLOW)
            );
            $this->settings->set('robots_category_brand', 
                $this->request->post('robots_category_brand', null, ROBOTS_INDEX_FOLLOW)
            );
            $this->settings->set('robots_category_brand_min_products',
                self::categoryBrandMinProducts($this->request->post('robots_category_brand_min_products', 'integer'))
            );
            $this->settings->set('robots_all_products_brand',
                self::allowedOrInherit(
                    $this->request->post('robots_all_products_brand', 'integer'),
                    self::ROBOTS_ALL_PRODUCTS_BRAND
                )
            );
            $this->settings->set('robots_category_features', 
                $this->request->post('robots_category_features', null, ROBOTS_INDEX_FOLLOW)
            );
            $this->settings->set('robots_catalog_other_filter', 
                $this->request->post('robots_catalog_other_filter', null, ROBOTS_INDEX_FOLLOW)
            );
            $this->settings->set('robots_catalog_filter_pagination', 
                $this->request->post('robots_catalog_filter_pagination', null, ROBOTS_INDEX_FOLLOW)
            );

            $this->settings->set('max_brands_filter_depth', $this->request->post('max_brands_filter_depth', 'integer', 0));
            $this->settings->set('max_other_filter_depth', $this->request->post('max_other_filter_depth', 'integer', 0));
            $this->settings->set('max_features_filter_depth', $this->request->post('max_features_filter_depth', 'integer', 0));
            $this->settings->set('max_features_values_filter_depth', $this->request->post('max_features_values_filter_depth', 'integer', 0));
            $this->settings->set('max_filter_depth', $this->request->post('max_filter_depth', 'integer', 0));

            foreach (array_keys(BrandFeatureLandingsHelper::SETTINGS_MAX) as $setting) {
                $this->settings->set($setting, self::brandFeatureLandingsSetting($setting, $this->request->post($setting, 'integer')));
            }
            
            $this->design->assign('message_success', 'saved');
        }
        
        $this->design->assign('brand_feature_landings_defaults', [
            'min_products' => BrandFeatureLandingsHelper::DEFAULT_MIN_PRODUCTS,
            'max_share' => BrandFeatureLandingsHelper::DEFAULT_MAX_SHARE,
        ]);

        $this->response->setContent('settings_indexing.tpl');
    }

    private static function allowedOrInherit(int $value, array $allowed): int
    {
        return in_array($value, $allowed, true) ? $value : 0;
    }

    /** 0 — без мінімуму, як MetaRobotsHelper::getCategoryBrandMinProducts() трактує значення поза межами. */
    private static function categoryBrandMinProducts(int $value): int
    {
        return $value >= 1 && $value <= MetaRobotsHelper::CATEGORY_BRAND_MIN_PRODUCTS_MAX ? $value : 0;
    }

    /** 0 — типове значення BrandFeatureLandingsHelper. */
    private static function brandFeatureLandingsSetting(string $setting, int $value): int
    {
        return $value >= 1 && $value <= BrandFeatureLandingsHelper::SETTINGS_MAX[$setting] ? $value : 0;
    }
}