{* Посадкові «бренд + значення властивості»: ядро передає лише сторінки, відкриті для індексу.
   На мобільному посилання понад 12 ховає кнопка (js), але всі вони лишаються в HTML сервера. *}
<nav class="brand_feature_landings" aria-labelledby="brand_feature_landings_title" data-landings>
    <h2 class="brand_feature_landings__title" id="brand_feature_landings_title">{$lang->brand_feature_landings_title|escape}</h2>
    <ul class="brand_feature_landings__list" id="brand_feature_landings_list">
        {foreach $brand_feature_landings as $landing}
            <li class="brand_feature_landings__item"{if $landing@iteration > 12} data-landings-extra{/if}>
                <a class="brand_feature_landings__link" href="{$landing->url|escape}">{$landing->value_name|escape} {$landing->brand_name|escape}{if empty($category)}<span class="brand_feature_landings__category"> · {$landing->category_name|escape}</span>{/if}</a>
            </li>
        {/foreach}
    </ul>
    {if $brand_feature_landings|count > 12}
        <button type="button" class="brand_feature_landings__more" aria-expanded="false" aria-controls="brand_feature_landings_list" data-landings-more hidden>{$lang->brand_feature_landings_more|escape}</button>
    {/if}
</nav>
