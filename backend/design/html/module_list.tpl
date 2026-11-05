{foreach $modules as $module}
    <div class="fn_row okay_list_body_item fn_sort_item">

        <div class="okay_list_row">
            {if $module->status !== 'Not Installed'}
                <input type="hidden" name="positions[{$module->id}]" value="{$module->position|escape}">
            {/if}

            <div class="okay_list_boding okay_list_drag move_zone">
                {if $module->status !== 'Not Installed'}{include file='svg_icon.tpl' svgId='drag_vertical'}{/if}
            </div>

            <div class="okay_list_boding okay_list_check">
                {if $module->status !== 'Not Installed'}
                    <input class="hidden_check" type="checkbox" id="id_{$module->id}" name="check[]" value="{$module->id}"/>
                    <label class="okay_ckeckbox" for="id_{$module->id}"></label>
                {/if}
            </div>

            <div class="okay_list_boding okay_list_photo">
                {if $module->preview}
                    {if $module->backend_main_controller}
                        <a href="{url controller=[{$module->vendor|escape},{$module->module_name|escape},{$module->backend_main_controller|escape}] id=null return=$smarty.server.REQUEST_URI}">
                            <img height="55" width="55" src="{$rootUrl}/{$module->preview|escape}"/>
                        </a>
                    {else}
                        <img height="55" width="55" src="{$rootUrl}/{$module->preview|escape}"/>
                    {/if}
                {else}
                    {if $module->backend_main_controller}
                        <a href="{url controller=[{$module->vendor|escape},{$module->module_name|escape},{$module->backend_main_controller|escape}] id=null return=$smarty.server.REQUEST_URI}">
                            {include file='svg_icon.tpl' svgId='modules_icon'}
                        </a>
                    {else}
                        {include file='svg_icon.tpl' svgId='modules_icon'}
                    {/if}
                {/if}
            </div>

            <div class="okay_list_boding okay_list_module_name">
                <div class="text_600 mr-1">
                    <div class="module_official__name">
                        {if $module->backend_main_controller}
                            <a href="{url controller=[{$module->vendor|escape},{$module->module_name|escape},{$module->backend_main_controller|escape}] id=null return=$smarty.server.REQUEST_URI}">
                                {$module->vendor|escape}/{$module->module_name|escape}
                            </a>
                        {else}
                            {$module->vendor|escape}/{$module->module_name|escape}
                        {/if}
                    </div>
                </div>

                {if $module->versionControl->greaterThan($module->version, $module->params->getVersion())}
                    <div class="mt-q">
                        <span class="text_attention font_12 text_600">
                            {$btr->module_downgrade_warning} {$module->params->getVersion()}
                        </span>
                    </div>
                {/if}

                <div class="mt-q">
                    <div class="fn_switch for_developer_toggle">
                        <span>{$btr->module_learn_more|escape}</span>
                        <span class="fn_icon_arrow m-t-2 rotate_180">{include file='svg_icon.tpl' svgId='chevron_down'}</span>
                    </div>
    
                    <div style="display: none;">
                        <div class="mt-q font_12 text_grey text_500">
                            <span>{$btr->module_version|escape}:</span>
                            <span class="text_dark">{$module->version|escape}</span>
                        </div>
    
                        {if !empty($module->params->getOkayVersion()) && $module->params->getOkayVersion() != $config->version}
                            <div class="mt-q font_12 text_grey text_500">
                                <span>{$btr->module_okay_version|escape}:</span>
                                <span class="text_dark">{$module->params->getOkayVersion()|escape}</span>
                            </div>
                        {/if}
    
                        <div class="mt-q font_12 text_grey text_500">
                            <span>{$btr->module_type|escape}:</span>
                            <span class="text_dark">{if $module->type}{$module->type|escape}{else}{$btr->not_used_module_type}{/if}</span>
                        </div>
    
                        {if $module->params->getVendorEmail()}
                            <div class="mt-q font_12 text_grey text_500">
                                <span class="">{$btr->module_vendor_email|escape}:</span>
                                <a class="okay_list_module_name_link" href="mailto:{$module->params->getVendorEmail()|escape}">{$module->params->getVendorEmail()|escape}</a>
                            </div>
                        {/if}
    
                        {if $module->params->getVendorSite()}
                            <div class="mt-q font_12 text_grey text_500">
                                <span class="">{$btr->module_vendor_site|escape}:</span>
                                <a class="okay_list_module_name_link" href="{$module->params->getVendorSite()|escape}" target="_blank">{$module->params->getVendorSite()|escape}</a>
                            </div>
                        {/if}
                    </div>
                </div>
            </div>

            <div class="okay_list_boding okay_list_module_version hidden-md-down">
                <div class="text_700">{$module->version|escape}</div>

                {if $module->versionControl->lessThan($module->version, $module->params->getVersion())}
                    <button type="button" class="fn_update_module btn btn-warning btn--update mt-q hint-top-middle-t-info-s-small-mobile hint-anim" data-hint="{$btr->module_need_update} {$module->params->getVersion()}">{include 'svg_icon.tpl' svgId='refresh_icon'} {$module->params->getVersion()}</button>
                {/if}

                {if !empty($module->params->getOkayVersion()) && $module->params->getOkayVersion() != $config->version}
                    <div class="font_12 text_grey mt-h">
                        {$btr->module_okay_version|escape}: {$module->params->getOkayVersion()|escape}
                    </div>
                {/if}
            </div>
            
            <div class="okay_list_boding okay_list_status">
                {if $module->status === 'Not Installed'}
                    <button class="btn btn_mini btn-info" name="install_module" value="{$module->vendor|escape}/{$module->module_name|escape}">{$btr->install_module}</button>
                {else}
                    <label class="switch switch-default">
                        <input class="switch-input fn_ajax_action {if $module->enabled}fn_active_class{/if}" data-controller="module" data-action="enabled" data-id="{$module->id}" name="enabled" value="1" type="checkbox"  {if $module->enabled}checked=""{/if}/>
                        <span class="switch-label"></span>
                        <span class="switch-handle"></span>
                    </label>
                {/if}
            </div>

            <div class="okay_list_setting okay_list_products_setting">
                {if $module->backend_main_controller}
                        <a data-hint="{$btr->module_action_setting|escape}" class="setting_icon setting_icon_setting hint-bottom-middle-t-info-s-small-mobile hint-anim" href="{url controller=[{$module->vendor|escape},{$module->module_name|escape},{$module->backend_main_controller|escape}] id=null return=$smarty.server.REQUEST_URI}">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 0 1 1.45.12l.773.774c.39.389.44 1.002.12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.93.78l.893.15c.543.09.94.559.94 1.109v1.094c0 .55-.397 1.02-.94 1.11l-.894.149c-.424.07-.764.383-.929.78-.165.398-.143.854.107 1.204l.527.738c.32.447.269 1.06-.12 1.45l-.774.773a1.125 1.125 0 0 1-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.398.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527c-.447.32-1.06.269-1.45-.12l-.773-.774a1.125 1.125 0 0 1-.12-1.45l.527-.737c.25-.35.272-.806.108-1.204-.165-.397-.506-.71-.93-.78l-.894-.15c-.542-.09-.94-.56-.94-1.109v-1.094c0-.55.398-1.02.94-1.11l.894-.149c.424-.07.765-.383.93-.78.165-.398.143-.854-.108-1.204l-.526-.738a1.125 1.125 0 0 1 .12-1.45l.773-.773a1.125 1.125 0 0 1 1.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                            </svg>
                        </a>
                {/if}
                {if $module->status !== 'Not Installed'}
                    <a data-hint="{$btr->files_list_module|escape}" class="setting_icon setting_icon_files hint-bottom-middle-t-info-s-small-mobile hint-anim" href="{url controller='ModuleDesignAdmin' vendor=$module->vendor module_name=$module->module_name}">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17.25 6.75 22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3-4.5 16.5" />
                        </svg>                          
                    </a>
                {/if}

            </div>

            <div class="okay_list_boding okay_list_close">
                {if $module->status !== 'Not Installed'}
                    <button data-hint="{$btr->general_delete|escape}" type="button" class="btn_close fn_remove hint-bottom-right-t-info-s-small-mobile  hint-anim" data-toggle="modal" data-target="#fn_action_modal" onclick="success_action($(this));">
                        {include file='svg_icon.tpl' svgId='trash'}
                    </button>
                {/if}
            </div>
        </div>
    </div>
{/foreach}

