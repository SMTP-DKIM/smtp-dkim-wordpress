jQuery(function($){
    var d = window.smtpdkimcAdmin || {};
    var i = d.i18n || {};

    if(d.justActivated && d.needPrivateKey){
        setTimeout(function(){
            var $card = $('#wpswd-dkim-card');
            var $key  = $('#dkim_private_key');
            if($card.length){
                $('html,body').animate({scrollTop: $card.offset().top - 80}, 600, function(){
                    $key.css({
                        'border'     : '2px solid #1a56ff',
                        'box-shadow' : '0 0 0 4px rgba(26,86,255,.2)',
                        'transition' : 'box-shadow .3s'
                    });
                    $key.focus();
                    setTimeout(function(){
                        $key.css({'box-shadow': 'none'});
                    }, 4000);
                });
            }
        }, 400);
    }

    function statusIcon(s){
        if(s==='ok')      return '<span style="color:#2e7d32;font-size:1.1em">&#x2705;</span>';
        if(s==='warning') return '<span style="color:#e65100;font-size:1.1em">&#x26A0;&#xFE0F;</span>';
        return '<span style="color:#c62828;font-size:1.1em">&#x274C;</span>';
    }
    function statusBadge(s,label){
        var cls = s==='ok'?'ok':s==='warning'?'warn':'bad';
        var ico = s==='ok'?'&#x2705; ':'&#x274C; ';
        return '<span class="wpswd-dns-badge wpswd-dns-badge-'+cls+'">'+ico+label+'</span>';
    }
    function buildFix(fix){
        if(!fix) return '';
        var isOptional = fix.optional === true;
        var headerStyle = isOptional ? 'background:#f0f8e8;border-left:4px solid #8bc34a' : '';
        var headerIcon  = isOptional ? i.dns_fix_optional : i.dns_fix_how;
        return '<div class="wpswd-dns-fix" style="'+headerStyle+'"><strong>'+headerIcon+' :</strong>'+
            '<table>'+
            '<tr><td>'+i.dns_fix_type+'</td><td><code>'+fix.type+'</code></td></tr>'+
            '<tr><td>'+i.dns_fix_name+'</td><td><code>'+fix.name+'</code></td></tr>'+
            '<tr><td>'+i.dns_fix_value+'</td><td><code>'+fix.value+'</code></td></tr>'+
            '</table>'+
            '<p style="margin:8px 0 0;color:#555;font-size:12px">&#x2139;&#xFE0F; '+fix.note+'</p>'+
            '</div>';
    }
    function buildRow(label,data,extra){
        var issues=(data.issues||[]).map(function(x){
            return '<p class="wpswd-dns-issue">&#x274C; '+x+'</p>';
        }).join('');
        var warnings=(data.warnings||[]).map(function(w){
            return '<p style="color:#856404;font-size:13px;margin:4px 0">'+w+'</p>';
        }).join('');
        var record='';
        if(data.record){
            var isLong = data.record.length > 100;
            var style  = isLong ? 'max-height:120px;overflow-y:auto;' : '';
            record = '<div class="wpswd-dns-record" style="'+style+'">'+data.record+'</div>';
            if(isLong) record += '<p style="font-size:11px;color:#888;margin:2px 0 6px">'+i.dkim_scroll_note+'</p>';
        }
        return '<div class="wpswd-dns-row">'+
            '<div class="wpswd-dns-label">'+statusIcon(data.status)+' '+label+'</div>'+
            '<div>'+record+(extra||'')+issues+warnings+buildFix(data.fix)+'</div>'+
            '</div>';
    }
    function renderDNS(r){
        var allOk = r.spf.status==='ok' && r.dkim.status==='ok' && r.dmarc.status==='ok';
        var html = '<h3 style="margin:0 0 12px">'+i.dns_results_for+' <strong>'+r.domain+'</strong></h3>';
        html += '<div class="wpswd-dns-summary">';
        html += '<span>SPF '+statusBadge(r.spf.status,'SPF')+'</span>';
        html += '<span>'+i.dkim_pub_key_lbl+' '+statusBadge(r.dkim.status,i.dkim_pub_key_lbl)+'</span>';
        html += '<span>DMARC '+statusBadge(r.dmarc.status,'DMARC')+'</span>';
        html += '</div>';
        if(allOk) html += '<div class="wpswd-lic-active" style="margin-bottom:16px">&#x1F389; <strong>'+i.dns_all_ok+'</strong></div>';
        var spfExtra = r.spf.strength==='softfail'?'<p style="font-size:12px;color:#888;margin:4px 0">'+i.spf_softfail_lbl+'</p>':
                       r.spf.strength==='strict'  ?'<p style="font-size:12px;color:#2e7d32;margin:4px 0">'+i.spf_strict_lbl+'</p>':'';
        html += buildRow('SPF',r.spf,spfExtra);
        var dkimExtra = '';
        dkimExtra += '<div style="background:#e8f4fd;border-left:3px solid #2196F3;padding:7px 10px;border-radius:3px;margin:6px 0;font-size:12px;color:#0d47a1">';
        dkimExtra += i.dns_pub_key_note;
        dkimExtra += '</div>';
        if(r.dkim.key_bits) dkimExtra += '<p style="font-size:12px;color:'+(r.dkim.key_bits>=2048?'#2e7d32':'#c62828')+';margin:4px 0">'+i.dkim_key_size_lbl+' '+r.dkim.key_bits+' '+i.dns_rsa_bits+(r.dkim.key_bits>=2048?' '+i.dkim_optimal_lbl:' '+i.dkim_min_bits_lbl)+'</p>';
        if(r.dkim.found_alt) dkimExtra += '<p style="font-size:12px;color:#e65100;margin:4px 0">&#171;'+r.dkim.found_alt+'&#187; '+i.dns_alt_sel+'</p>';
        var dkimRowData = Object.assign({}, r.dkim, {record: r.dkim.public_key || r.dkim.record});
        html += buildRow(i.dkim_pub_key_lbl,dkimRowData,dkimExtra);
        var dmarcExtra = r.dmarc.policy?'<p style="font-size:12px;color:'+(r.dmarc.policy==='none'?'#c62828':'#2e7d32')+';margin:4px 0">'+i.dmarc_policy_lbl+' p='+r.dmarc.policy+(r.dmarc.rua?' &middot; '+r.dmarc.rua:' &middot; '+i.dmarc_no_rua_warn)+'</p>':'';
        html += buildRow('DMARC',r.dmarc,dmarcExtra);
        var dkimSign = d.dkimSigningStatus || 'premium_only';
        var dkimSignIcon, dkimSignContent;
        if(dkimSign==='active'){
            dkimSignIcon='<span style="color:#2e7d32;font-size:1.1em">&#x2705;</span>';
            dkimSignContent='<span style="color:#2e7d32;font-weight:600">'+(i.dkim_sign_active||'Active')+'</span>';
        }else if(dkimSign==='inactive'){
            dkimSignIcon='<span style="color:#c62828;font-size:1.1em">&#x274C;</span>';
            dkimSignContent='<span style="color:#c62828">'+(i.dkim_sign_inactive||'Non configurée')+'</span>';
        }else{
            dkimSignIcon='&#x1F512;';
            dkimSignContent=i.dkim_sign_premium||'Available in the <strong>Premium version</strong> &mdash; <a href="https://smtp-dkim.com" target="_blank" style="font-weight:700">smtp-dkim.com</a>';
        }
        html += '<div class="wpswd-dns-row"><div class="wpswd-dns-label">'+dkimSignIcon+' '+(i.dkim_sign_lbl||'DKIM Signing Private Key')+'</div><div>'+dkimSignContent+'</div></div>';
        return html;
    }

    // === Debug log ===
    function refreshDebugLog(){
        var $btn  = $('#wpswd-debug-refresh');
        var $ta   = $('#wpswd-debug-log');
        var $meta = $('#wpswd-debug-meta');
        $btn.prop('disabled', true).text('⏳');
        $.post(ajaxurl, {action:'smtpdkimc_get_debug_log', nonce:d.nonceDebugLog}, function(resp){
            if(resp.success){
                $ta.val(resp.data.log || '');
                $ta[0].scrollTop = $ta[0].scrollHeight;
                if($meta.length){
                    $meta.text(resp.data.exists
                        ? resp.data.size + ' — ' + resp.data.count + ' ' + (i.log_lines || 'lines')
                        : (i.log_no_file || 'No log file yet.'));
                }
            }
        }).always(function(){
            $btn.prop('disabled', false).text('🔄 ' + (i.log_refresh_btn || 'Refresh log'));
        });
    }

    $('#wpswd-debug-refresh').on('click', refreshDebugLog);

    $('#wpswd-debug-clear').on('click',function(){
        if(!confirm(i.confirm_clear || 'Clear the debug log?')) return;
        $.post(ajaxurl,{action:'smtpdkimc_clear_debug_log',nonce:d.nonceDebugLog},function(resp){
            if(resp.success){
                $('#wpswd-debug-log').val('');
                $('#wpswd-debug-meta').text('0 KB');
            }
        });
    });

    if($('#wpswd-debug-log').length){ refreshDebugLog(); }

    // === DNS scan ===
    $('#wpswd-dns-scan').on('click',function(){
        var domain   = $('#wpswd-dns-domain').val();
        var selector = $('#wpswd-dns-selector').val();
        var $res = $('#wpswd-dns-results');
        $res.show().html('<div class="wpswd-dns-loading"><span class="wpswd-dns-spinner"></span>'+i.dns_loading+'</div>');
        $.post(ajaxurl,{
            action:'smtpdkimc_dns_check',
            nonce:d.nonceDns,
            domain:domain, selector:selector
        },function(resp){
            if(!resp.success){ $res.html('<div class="wpswd-warn">'+i.dns_error+' '+resp.data+'</div>'); return; }
            $res.html(renderDNS(resp.data));
        }).fail(function(){ $res.html('<div class="wpswd-warn">'+(i.dns_network_error||'Network error. Please check your connection.')+'</div>'); });
    });

    function pollLogSilent(){
        var $ta   = $('#wpswd-debug-log');
        var $meta = $('#wpswd-debug-meta');
        if(!$ta.length) return;
        $.post(ajaxurl, {action:'smtpdkimc_get_debug_log', nonce:d.nonceDebugLog}, function(resp){
            if(resp.success){
                $ta.val(resp.data.log || '');
                $ta[0].scrollTop = $ta[0].scrollHeight;
                if($meta.length && resp.data.exists){
                    $meta.text(resp.data.size + ' — ' + resp.data.count + ' ' + (i.log_lines || 'lines'));
                }
            }
        });
    }

    // === Test email with live log polling ===
    function startTestWithLog(ajaxData, $spinner, $result, $btn) {
        $spinner.show();
        $btn.prop('disabled', true);
        $result.html('');
        $('html,body').animate({scrollTop: $('#wpswd-debug-card').offset().top - 80}, 400);
        var pollTimer = setInterval(pollLogSilent, 600);
        $.post(ajaxurl, ajaxData, function(resp){
            clearInterval(pollTimer);
            refreshDebugLog();
            var ok  = resp.success;
            var msg = ok ? (resp.data || '') : (resp.data || (i.dns_error || 'Error.'));
            var cls = ok ? 'wpswd-lic-active' : 'wpswd-warn';
            $result.html('<div class="'+cls+'" style="margin-top:6px">'+(ok?'&#x2705; ':'&#x274C; ')+msg+'</div>');
        }).fail(function(){
            clearInterval(pollTimer);
            $result.html('<div class="wpswd-warn">&#x274C; '+(i.dns_network_error||'Network error.')+'</div>');
        }).always(function(){
            $spinner.hide();
            $btn.prop('disabled', false);
        });
    }

    $('#wpswd-test-external-form').on('submit', function(e){
        e.preventDefault();
        var email = $('#wpswd-test-ext-email').val();
        if(!email){ return; }
        startTestWithLog(
            {action:'smtpdkimc_test_external', nonce:d.nonceTestExternal, email:email},
            $('#wpswd-test-ext-spinner'), $('#wpswd-test-ext-result'), $('#wpswd-test-ext-btn')
        );
    });

    // === License activation overlay ===
    $('#wpswd-lic-activate-btn').on('click', function(){
        var licKey = $.trim($('#wpswd-lic-key-input').val());
        if(!licKey){ alert(i.overlay_alert_empty); return; }

        var $overlay = $('#wpswd-lic-overlay');
        var $bar     = $('#wpswd-lic-overlay-bar');
        var $msg     = $('#wpswd-lic-overlay-msg');
        var $step    = $('#wpswd-lic-overlay-step');
        $overlay.addClass('active');
        $bar.css('width','15%');
        $msg.text(i.overlay_msg1);
        $step.text(i.overlay_step1);

        setTimeout(function(){
            $bar.css('width','45%');
            $msg.text(i.overlay_msg2);
            $step.text(i.overlay_step2);

            $.post(ajaxurl,{
                action:'smtpdkimc_dns_autodetect',
                nonce:d.nonceAutodetect
            },function(resp){
                $bar.css('width','80%');
                $step.text(i.overlay_step3);

                if(resp.success){
                    var data = resp.data;
                    if(data.domain && !$.trim($('#dkim_domain').val())){
                        $('#dkim_domain').val(data.domain);
                        $('input[name="dkim_domain"]').val(data.domain);
                    }
                    if(data.selector && !$.trim($('#dkim_selector').val())){
                        $('#dkim_selector').val(data.selector);
                        $('input[name="dkim_selector"]').val(data.selector);
                        $('#wpswd-dns-selector').val(data.selector);
                    }
                    $msg.text(data.selector_found
                        ? i.overlay_sel_found + ' "' + data.selector + '"'
                        : i.overlay_sel_manual);
                } else {
                    $msg.text(i.overlay_sel_manual);
                }

                setTimeout(function(){
                    $bar.css('width','100%');
                    $msg.text(i.overlay_msg4);
                    setTimeout(function(){
                        $('#wpswd-lic-form').submit();
                    }, 600);
                }, 400);
            }).fail(function(){
                setTimeout(function(){
                    $bar.css('width','100%');
                    $('#wpswd-lic-form').submit();
                }, 400);
            });
        }, 800);
    });
});
