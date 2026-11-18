{* Посадкові «бренд + значення властивості»: ядро передає лише сторінки, відкриті для індексу *}
<nav class="fn_brand_feature_landings brand_feature_landings" aria-labelledby="brand_feature_landings_title">
    <h2 class="brand_feature_landings__title" id="brand_feature_landings_title">{$lang->brand_feature_landings_title|escape}</h2>
    <ul class="brand_feature_landings__list">
        {foreach $brand_feature_landings as $landing}
            <li class="brand_feature_landings__item">
                <a class="brand_feature_landings__link" href="{$landing->url|escape}">{$landing->value_name|escape} {$landing->brand_name|escape}{if empty($category)}<span class="brand_feature_landings__category"> · {$landing->category_name|escape}</span>{/if}</a>
            </li>
        {/foreach}
    </ul>
</nav>
