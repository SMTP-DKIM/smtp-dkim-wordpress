(function($){
    var d = window.smtpdkimcDeact || {};
    var deactUrl = '';

    $(document).ready(function(){
        $('tr[data-slug]').each(function(){
            var $row = $(this);
            $row.find('a.deactivate-link, span.deactivate a, td.plugin-title ~ td a').each(function(){
                var href = $(this).attr('href') || '';
                if ( href.indexOf('action=deactivate') !== -1 && href.indexOf( encodeURIComponent(d.basename) ) !== -1 ) {
                    $(this).on('click', function(e){
                        e.preventDefault();
                        deactUrl = href;
                        $('#smtpdkimc-deact-overlay').addClass('active');
                        $('input[name="smtpdkimc_reason"]').prop('checked', false);
                        $('#smtpdkimc-comment').val('');
                        $('#smtpdkimc-sending').hide();
                    });
                }
            });
        });

        $('#the-list tr').find('span.deactivate a, td.column-description span.deactivate a').each(function(){
            var href = $(this).attr('href') || '';
            if ( href.indexOf('action=deactivate') !== -1 && href.indexOf( encodeURIComponent(d.basename) ) !== -1 ) {
                $(this).off('click').on('click', function(e){
                    e.preventDefault();
                    deactUrl = href;
                    $('#smtpdkimc-deact-overlay').addClass('active');
                    $('input[name="smtpdkimc_reason"]').prop('checked', false);
                    $('#smtpdkimc-comment').val('');
                    $('#smtpdkimc-sending').hide();
                });
            }
        });
    });

    $(document).on('click', '#smtpdkimc-btn-cancel', function(){
        $('#smtpdkimc-deact-overlay').removeClass('active');
    });

    $(document).on('click', '#smtpdkimc-deact-overlay', function(e){
        if ( e.target === this ) {
            $(this).removeClass('active');
        }
    });

    $(document).on('click', '#smtpdkimc-btn-skip', function(){
        if ( deactUrl ) window.location.href = deactUrl;
    });

    $(document).on('click', '#smtpdkimc-btn-submit', function(){
        var reason = $('input[name="smtpdkimc_reason"]:checked').val();
        if ( ! reason ) {
            alert(d.errTxt);
            return;
        }
        var comment = $('#smtpdkimc-comment').val();
        $('#smtpdkimc-btn-submit, #smtpdkimc-btn-skip, #smtpdkimc-btn-cancel').prop('disabled', true);
        $('#smtpdkimc-sending').show();

        $.post(d.ajaxUrl, {
            action  : 'smtpdkimc_deactivation_feedback',
            nonce   : d.nonce,
            reason  : reason,
            comment : comment
        }, function(){
            if ( deactUrl ) window.location.href = deactUrl;
        }).fail(function(){
            if ( deactUrl ) window.location.href = deactUrl;
        });
    });

})(jQuery);
