jQuery(document).ready(function($){
    $('#aspom-ajax-run').on('click', function(e){
        e.preventDefault();
        var btn = $(this);
        btn.prop('disabled', true).text('Running...');
        $.post(aspom_ajax.ajaxurl, { action: 'aspom_manual_run_ajax', nonce: aspom_ajax.nonce }, function(resp){
            btn.prop('disabled', false).text('Run Now (AJAX)');
            if (resp.success) {
                alert('Manual run completed.');
            } else {
                alert('Manual run failed: ' + resp.data);
            }
        }).fail(function(){
            btn.prop('disabled', false).text('Run Now (AJAX)');
            alert('Request failed.');
        });
    });

    $('#aspom-php-run').on('click', function(){
        var btn = $(this);
        btn.prop('disabled', true).val('Running...');
        // trigger a form POST to admin-post.php with action aspom_save_settings? we'll call Fetcher via AJAX instead
        $.post(aspom_ajax.ajaxurl, { action: 'aspom_manual_run_ajax', nonce: aspom_ajax.nonce }, function(resp){
            btn.prop('disabled', false).val('Run Now (Server)');
            $('#aspom-php-result').text(resp.success ? 'Manual run completed' : ('Error: ' + resp.data));
        }).fail(function(){
            btn.prop('disabled', false).val('Run Now (Server)');
            $('#aspom-php-result').text('Request failed');
        });
    });
});
