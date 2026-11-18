{* Посадкові «бренд + значення властивості»: ядро передає лише сторінки, відкриті для індексу *}
<nav class="fn_brand_feature_landings vs-landings" aria-labelledby="brand_feature_landings_title">
    <h2 class="vs-landings__title" id="brand_feature_landings_title">{$lang->brand_feature_landings_title|escape}</h2>
    <ul class="vs-chips vs-landings__list">
        {foreach $brand_feature_landings as $landing}
            <li>
                <a class="vs-chip vs-landings__link" href="{$landing->url|escape}">{$landing->value_name|escape} {$landing->brand_name|escape}{if empty($category)} · {$landing->category_name|escape}{/if}</a>
            </li>
        {/foreach}
    </ul>
</nav>
