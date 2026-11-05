{* Title *}
{$meta_title=$btr->modules_list_title scope=global}

{if $message_error}
    <div class="row">
        <div class="col-lg-12 col-md-12 col-sm-12">
            <div class="alert alert--center alert--icon alert--error">
                <div class="alert__content">
                    <div class="alert__title">{$message_error|escape}</div>
                </div>
            </div>
        </div>
    </div>
{/if}

{*Название страницы*}
<div class="row">
    <div class="col-lg-12 col-md-12">
        <div class="wrap_heading">
            <div class="box_heading heading_page">
                {$btr->modules_list_title|escape}
            </div>
        </div>
    </div>
</div>

{*Главная форма страницы*}
<div class="fn_toggle_wrap">
    {if $modules}
        <form class="fn_form_list" method="post">
            <div class="okay_list products_list bg_white mb-1 fn_sort_list">
                <input type="hidden" name="session_id" value="{$smarty.session.id}">
                {*Шапка таблицы*}
                <div class="okay_list_head">
                    <div class="okay_list_boding okay_list_drag"></div>
                    <div class="okay_list_heading okay_list_check">
                        <input class="hidden_check fn_check_all" type="checkbox" id="check_all_1" name="" value="" />
                        <label class="okay_ckeckbox" for="check_all_1"></label>
                    </div>
                    <div class="okay_list_heading okay_list_photo">{$btr->general_photo|escape}</div>
                    <div class="okay_list_heading okay_list_module_name">{$btr->general_name|escape}</div>
                    <div class="okay_list_heading okay_list_module_version hidden-md-down">{$btr->module_version|escape}</div>
                    <div class="okay_list_heading okay_list_status">{$btr->general_enable|escape}</div>
                    <div class="okay_list_heading okay_list_setting okay_list_products_setting">{$btr->general_activities|escape}</div>
                    <div class="okay_list_heading okay_list_close"></div>
                </div>

                {*Параметры элемента*}
                <div class="deliveries_wrap okay_list_body sortable fn_modules_list">
                    {include 'module_list.tpl'}
                </div>

                {*Блок массовых действий*}
                <div class="okay_list_footer fn_action_block">
                    <div class="okay_list_foot_left">
                        <div class="okay_list_boding okay_list_drag"></div>
                        <div class="okay_list_heading okay_list_check">
                            <input class="hidden_check fn_check_all" type="checkbox" id="check_all_2" name="" value=""/>
                            <label class="okay_ckeckbox" for="check_all_2"></label>
                        </div>
                        <div class="okay_list_option">
                            <select name="action" class="selectpicker form-control">
                                <option value="enable">{$btr->general_do_enable|escape}</option>
                                <option value="disable">{$btr->general_do_disable|escape}</option>
                                <option value="delete">{$btr->general_delete|escape}</option>
                                <option value="update">{$btr->module_update|escape}</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn_small btn_blue">
                        {include file='svg_icon.tpl' svgId='checked'}
                        <span>{$btr->general_apply|escape}</span>
                    </button>
                </div>
            </div>
        </form>
    {else}
        <div class="heading_box mt-1">
            <div class="text_grey">{$btr->no_modules|escape}</div>
        </div>
    {/if}
</div>

<script>
    $(document).on("click", ".fn_update_module", function () {
        $('.fn_form_list input[type="checkbox"][name*="check"]').prop('checked', false);
        $(this).closest(".fn_form_list").find('select[name="action"] option[value=update]').prop('selected', true);
        $(this).closest(".fn_row").find('input[type="checkbox"][name*="check"]').prop('checked', true);
        $(this).closest(".fn_form_list").trigger('submit');
    });

    $(document).on("click", ".fn_switch", function (e) {
    e.preventDefault();

    $(this).next().slideToggle(300);

    if ($(this).hasClass("active")) {
      $(this).removeClass("active");
    } else {
      $(this).addClass("active");
    }
  });
</script>
