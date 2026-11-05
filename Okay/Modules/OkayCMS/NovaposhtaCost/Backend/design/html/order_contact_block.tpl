<div class="fn_delivery_novaposhta"{if $delivery->module_id != $novaposhta_module_id} style="display: none;"{/if}>
    <div class="heading_box">
        {$btr->left_setting_np_title|escape}
    </div>
    <input name="novaposhta_city_id" type="hidden" value="{$novaposhta_delivery_data->city_id|escape}" />
    <input name="novaposhta_delivery_term" type="hidden" value="{$novaposhta_delivery_data->delivery_term|escape}" />

    {$isDoorDelivery = $delivery->settings['service_type'] == 'DoorsDoors' || $delivery->settings['service_type'] == 'WarehouseDoors'}

    <div class="fn_np_door_delivery_block"
        {if !$isDoorDelivery}
            style="display: none"
        {/if}
    >
        <div class="mb-1">
            <div class="heading_label">{$btr->order_np_city}</div>
            <input type="text" name="novaposhta_city" class="fn_newpost_city_name fn_np_door_city form-control" autocomplete="off" value="{$novaposhta_delivery_data->city_name|escape}"{if !$isDoorDelivery} disabled{/if}>
        </div>
        <div class="mb-1">
            <div class="heading_label">{$btr->order_np_street}</div>
            <input type="text" name="novaposhta_street" class="fn_newpost_street form-control" autocomplete="off" value="{$novaposhta_delivery_data->street|escape}"{if !$isDoorDelivery} disabled{/if}>
        </div>
        <div class="mb-1">
            <div class="heading_label">{$btr->order_np_house}</div>
            <input type="text" name="novaposhta_house" class="form-control" autocomplete="off" value="{$novaposhta_delivery_data->house|escape}"{if !$isDoorDelivery} disabled{/if}>
        </div>
        <div class="mb-1">
            <div class="heading_label">{$btr->order_np_apartment}</div>
            <input type="text" name="novaposhta_apartment" class="form-control" autocomplete="off" value="{$novaposhta_delivery_data->apartment|escape}"{if !$isDoorDelivery} disabled{/if}>
        </div>

        <input name="novaposhta_city_name" class="fn_np_clear" type="hidden" value="{$novaposhta_delivery_data->city_name|escape}"{if !$isDoorDelivery} disabled{/if}/>
        <input name="novaposhta_area_name" class="fn_np_clear" type="hidden" value="{$novaposhta_delivery_data->area_name|escape}"{if !$isDoorDelivery} disabled{/if}/>
        <input name="novaposhta_region_name" class="fn_np_clear" type="hidden" value="{$novaposhta_delivery_data->region_name|escape}"{if !$isDoorDelivery} disabled{/if}/>
        <input name="novaposhta_street_name" class="fn_np_clear" type="hidden" value="{$novaposhta_delivery_data->street|escape}"{if !$isDoorDelivery} disabled{/if}/>

        <input name="novaposhta_door_delivery" type="hidden" value="1"{if !$isDoorDelivery} disabled{/if}/>
    </div>
    <div class="fn_np_warehouse_delivery_block"
        {if $isDoorDelivery}
            style="display: none"
        {/if}
    >
        <input name="novaposhta_warehouse_id" type="hidden" value="{$novaposhta_delivery_data->warehouse_id|escape}"{if $isDoorDelivery} disabled{/if}/>
        
        <div class="mb-1">
            <div class="heading_label">{$btr->order_np_city}</div>
            <input type="text" class="fn_newpost_city_name fn_np_warehouse_city form-control" autocomplete="off" value="{$novaposhta_delivery_data->city_id|newpost_city}"{if $isDoorDelivery} disabled{/if}>
        </div>
        <div class="mb-1">
            <div class="heading_label">{$btr->order_np_warehouse}
                <i class="fn_tooltips hint-bottom-middle-t-white-s-small-mobile hint-anim" data-hint="{$btr->np_update_address_info|escape}" tabindex="0" role="img" aria-label="{$btr->np_update_address_info|escape}">
                    {include file='svg_icon.tpl' svgId='icon_tooltips'}
                </i>
            </div>
            <select name="novaposhta_warehouse" tabindex="1" class="selectpicker form-control warehouses_novaposhta" data-live-search="true"{if $isDoorDelivery} disabled{/if}></select>
        </div>
    </div>
    
    <div class="mb-1">
        <div class="heading_label">
            <input type="checkbox" id="novaposhta_redelivery" name="novaposhta_redelivery" value="1" {if $novaposhta_delivery_data->redelivery}checked{/if}/>
            <label for="novaposhta_redelivery">{$btr->order_np_redelivery}</label>
        </div>
    </div>
    {if empty($novaposhta_delivery_data->city_id)}
        <div class="mb-1 alert alert--error">
            <div class="heading_label alert__content">
                {$btr->np_error_city_id|escape}
            </div>
        </div>
    {else}
        <div class="mb-1">
            <div class="heading_label">
                <span class="fn_np_term"{if !$novaposhta_delivery_data->delivery_term} style="display: none;"{/if}>{$btr->order_np_term}: <span>{$novaposhta_delivery_data->delivery_term|escape}</span></span>
                <a href="#" class="fn_np_recalc_price">{$btr->order_np_calc}</a>
            </div>
        </div>
    {/if}
</div>

{literal}
<script src="design/js/autocomplete/jquery.autocomplete-min.js"></script>

<script>

    toastr.options = {
        closeButton: true,
        newestOnTop: true,
        progressBar: true,
        positionClass: 'toast-top-right',
        preventDuplicates: false,
        onclick: null
    };
    
    $('.fn_np_recalc_price').on('click', function(e) {
        e.preventDefault();
        let selected_city = $('input[name="novaposhta_city_id"]').val();
        let delivery_id = $('select[name="delivery_id"]').children(':selected').val();
        let redelivery = $('input[name="novaposhta_redelivery"]').is(':checked') ? 1 : 0;
        $.ajax({
            url: okay.router['OkayCMS_NovaposhtaCost_calc'],
            data: {
                city: selected_city,
                redelivery: redelivery,
                delivery_id: delivery_id,
                currency: '{/literal}{$currency->id}{literal}',
                order_id: '{/literal}{$order->id}{literal}'
            },
            dataType: 'json',
            success: function(data) {
                
                if (data.price_response.success) {
                    toastr.success('', "{/literal}{$btr->toastr_success|escape}{literal}");
                    $('input[name="delivery_price"]').val(data.price_response.price);
                }

                if (data.term_response.success) {
                    $('input[name="novaposhta_delivery_term"]').val(data.term_response.term);
                    $('.fn_np_term').show().children('span').text(data.term_response.term);
                } else {
                    $('.fn_np_term').parent().hide();
                }
            }
        });
    });

    let doorsDeliveries = {/literal}{json_encode($doorsDeliveries)}{literal};
    let warehousesDeliveries = {/literal}{json_encode($warehousesDeliveries)}{literal};

    $('select[name="delivery_id"]').on('change', function () {
        if ($(this).children(':selected').data('module_id') == '{/literal}{$novaposhta_module_id}{literal}') {
            $('.fn_delivery_novaposhta').show();
            let deliveryId = $(this).children(':selected').val();
            let doorDeliveryBlock = $('.fn_np_door_delivery_block');
            let warehouseDeliveryBlock = $('.fn_np_warehouse_delivery_block');
            // json_encode дає числа, а value у select — рядок.
            if (doorsDeliveries.map(String).includes(String(deliveryId))) {
                doorDeliveryBlock.show().find('input, select').attr('disabled', false);
                warehouseDeliveryBlock.hide().find('input, select').attr('disabled', true);
                npUseCityRef(true);
            } else if (warehousesDeliveries.map(String).includes(String(deliveryId))) {
                doorDeliveryBlock.hide().find('input, select').attr('disabled', true);
                warehouseDeliveryBlock.show().find('input, select').attr('disabled', false);
                npUseCityRef(false);
            }
        } else {
            $('.fn_delivery_novaposhta').hide();
        }
    });

    // Місто адреси й місто відділення беруться з різних довідників НП (searchSettlements
    // і getCities), тож у кожного блоку свій автокомпліт і свій Ref. Спільне приховане
    // novaposhta_city_id отримує Ref активного блоку: інакше місто, вибране в одному
    // блоці, зберігалось би з назвою, вулицею чи відділенням з іншого.
    const npDoorCityInput = $('.fn_np_door_city');
    const npWarehouseCityInput = $('.fn_np_warehouse_city');
    const npDoorStreetInput = $('.fn_newpost_street');
    const npCityId = $('input[name="novaposhta_city_id"]');
    const npDoorStreetName = $('input[name="novaposhta_street_name"]');
    const npDoorCityFields = $('input[name="novaposhta_city_name"], input[name="novaposhta_area_name"], input[name="novaposhta_region_name"]');
    const npStoredCityRef = {/literal}'{$novaposhta_delivery_data->city_id|escape:'javascript'}'{literal};

    // Назву показують лише ті поля, для чиїх довідників Ref підходить.
    let npDoorCityRef = npDoorCityInput.val() !== '' ? npStoredCityRef : '';
    let npWarehouseCityRef = npWarehouseCityInput.val() !== '' ? npStoredCityRef : '';
    let npDoorCity = npDoorSnapshot();
    let npDoorCityEdited = false;
    let npDoorStreetLabel = npDoorStreetInput.val();
    let npDoorStreetValue = npDoorStreetName.val();

    function npDoorSnapshot() {
        return {
            ref: npDoorCityRef,
            label: npDoorCityInput.val(),
            fields: npDoorCityFields.map(function() { return $(this).val(); }).get()
        };
    }

    function npUseCityRef(isDoor) {
        npCityId.val(isDoor ? npDoorCityRef : npWarehouseCityRef);
    }

    // Підказки — дані з API НП, тож показуємо їх як текст.
    function npSuggestionText(suggestion) {
        return $('<div>').text(suggestion.value || '').html();
    }

    function npClearDoorStreet() {
        npDoorStreetInput.val('');
        npDoorStreetName.val('');
        npDoorStreetLabel = '';
        npDoorStreetValue = '';
    }

    // Вулиці шукаються за Ref населеного пункту (searchSettlementStreets).
    function setStreetAutocomplete(settlementRef) {
        if (!settlementRef) {
            return;
        }
        npDoorStreetInput.devbridgeAutocomplete({
            serviceUrl: okay.router['OkayCMS_NovaposhtaCost_find_street'] + '?city_ref=' + encodeURIComponent(settlementRef),
            minChars: 1,
            noCache: true,
            // Не пропонуємо вулиці міста, яке стерли з поля.
            onSearchStart: function() {
                return npDoorCityRef !== '';
            },
            onSelect: function(suggestion) {
                npDoorStreetLabel = npDoorStreetInput.val();
                npDoorStreetValue = suggestion.value || '';
                npDoorStreetName.val(npDoorStreetValue);
            },
            formatResult: npSuggestionText
        });
    }

    setStreetAutocomplete(npDoorCityRef);

    // У частини замовлень city_id — Ref міста (getCities), з яким пошук вулиць порожній.
    // Знаходимо Ref населеного пункту за назвою; збережений Ref не змінюється.
    if (npDoorCityRef !== '' && npDoorCityInput.val() !== '') {
        $.ajax({
            url: okay.router['OkayCMS_NovaposhtaCost_find_city_for_door'],
            data: { query: npDoorCityInput.val() },
            dataType: 'json',
            success: function(data) {
                const match = (data.suggestions || []).find(function(s) {
                    return s.ref === npDoorCityRef || s.delivery_city === npDoorCityRef;
                });
                if (match && match.ref !== npDoorCityRef) {
                    setStreetAutocomplete(match.ref);
                }
            }
        });
    }

    npDoorCityInput.devbridgeAutocomplete({
        serviceUrl: okay.router['OkayCMS_NovaposhtaCost_find_city_for_door'],
        minChars: 1,
        noCache: true,
        onSelect: function(suggestion) {
            const cityRef = suggestion.ref || '';
            if (cityRef !== npDoorCity.ref) {
                npClearDoorStreet();
            }
            npDoorCityRef = cityRef;
            $('input[name="novaposhta_city_name"]').val(suggestion.city || '');
            $('input[name="novaposhta_area_name"]').val(suggestion.area || '');
            $('input[name="novaposhta_region_name"]').val(suggestion.region || '');
            npDoorCity = npDoorSnapshot();
            npDoorCityEdited = false;
            npDoorCityInput.removeClass('error').next('.fn_np_city_hint').remove();
            npUseCityRef(true);
            npCityId.trigger('change');
            setStreetAutocomplete(cityRef);
        },
        formatResult: npSuggestionText
    });

    // Текст без вибору підказки не має Ref. Повернення до вибраного тексту відновлює місто.
    npDoorCityInput.on('input', function() {
        if (npDoorCityInput.val() === npDoorCity.label) {
            npDoorCityRef = npDoorCity.ref;
            npDoorCityFields.each(function(i) { $(this).val(npDoorCity.fields[i]); });
            npDoorCityEdited = false;
        } else {
            npDoorCityRef = '';
            npDoorCityFields.val('');
            npDoorCityEdited = true;
        }
        npUseCityRef(true);
    });

    npDoorStreetInput.on('input', function() {
        npDoorStreetName.val(npDoorStreetInput.val() === npDoorStreetLabel ? npDoorStreetValue : '');
    });

    // Місто, змінене без вибору зі списку, не зберігаємо: у замовленні лишилося б
    // місто без Ref і області. Старі замовлення без Ref це не зачіпає, поки поле не чіпали.
    npDoorCityInput.closest('form').on('submit', function(e) {
        if (!npDoorCityEdited || !npDoorCityInput.is(':visible')) {
            return;
        }
        e.preventDefault();
        npDoorCityInput.addClass('error').next('.fn_np_city_hint').remove();
        $('<div class="fn_np_city_hint text_warning mt-q"></div>')
            .text('{/literal}{$btr->np_city_choose_from_list|escape:'javascript'}{literal}')
            .insertAfter(npDoorCityInput);
        npDoorCityInput.trigger('focus');
    });

    npWarehouseCityInput.devbridgeAutocomplete({
        serviceUrl: okay.router['OkayCMS_NovaposhtaCost_find_city'],
        minChars: 1,
        maxHeight: 420,
        noCache: true,
        onSelect: function(suggestion) {
            $('input[name="novaposhta_warehouse_id"]').val('');
            npWarehouseCityRef = suggestion.data.ref;
            npUseCityRef(false);
            showWarehouses(npWarehouseCityRef);
        },
        formatResult: npSuggestionText
    });

    if (npWarehouseCityRef !== '') {
        showWarehouses(npWarehouseCityRef);
    }

    
    function showWarehouses(cityRef) {
        let selectedWarehouseRef = $('input[name="novaposhta_warehouse_id"]').val();

        $.ajax({
            url: okay.router['OkayCMS_NovaposhtaCost_get_warehouses'],
            data: {city: cityRef},
            dataType: 'json',
            success: function(data) {
                let warehousesSelect = $('select.warehouses_novaposhta');
                warehousesSelect.html('');
                let option = $('<option value="" data-warehouse_ref="" ' +
                    (!selectedWarehouseRef ? 'selected' : '') +
                '>{/literal}{$btr->np_warehouse_not_selected|escape}{literal}</option>')
                warehousesSelect.append(option);
                if (data.success) {
                    for (let warehouseKey in data.warehouses) {
                        let warehouse = data.warehouses[warehouseKey];
                        let option = $('<option value="' + warehouse.name + '" ' +
                            'data-warehouse_ref="' + warehouse.ref + '"' +
                            (selectedWarehouseRef && selectedWarehouseRef == warehouse.ref ? 'selected' : '') +
                            '>' + warehouse.name + '</option>')
                        warehousesSelect.append(option);
                    }

                    warehousesSelect.show();
                    warehousesSelect.selectpicker('refresh');
                } else {
                    warehousesSelect.html('').hide();
                }
            }
        });
    }
    
    $('select.warehouses_novaposhta').on('change', function() {
        $('input[name="novaposhta_warehouse_id"]').val($(this).children(':selected').data('warehouse_ref'));
    });
</script>
{/literal}