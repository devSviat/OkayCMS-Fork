{* Посадкові «бренд + значення властивості»: ядро передає лише сторінки, відкриті для індексу.
   На мобільному посилання понад 12 ховає кнопка (js), але всі вони лишаються в HTML сервера. *}
<nav class="vs-landings" aria-labelledby="brand_feature_landings_title" data-landings>
    <h2 class="vs-landings__title" id="brand_feature_landings_title">{$lang->brand_feature_landings_title|escape}</h2>
    <ul class="vs-chips vs-landings__list" id="brand_feature_landings_list">
        {foreach $brand_feature_landings as $landing}
            <li{if $landing@iteration > 12} data-landings-extra{/if}>
                <a class="vs-chip vs-landings__link" href="{$landing->url|escape}">{$landing->value_name|escape} {$landing->brand_name|escape}{if empty($category)} · {$landing->category_name|escape}{/if}</a>
            </li>
        {/foreach}
    </ul>
    {if $brand_feature_landings|count > 12}
        <button type="button" class="vs-chip vs-landings__more" aria-expanded="false" aria-controls="brand_feature_landings_list" data-landings-more hidden>{$lang->brand_feature_landings_more|escape}</button>
    {/if}
</nav>
