jQuery(function($){
    $('#wcapm-run-bulk-price').on('click',function(){
        if(!confirm('Update ALL published products?'))return;
        var $btn=$(this).prop('disabled',true).text('Updating...');
        $.post(wcapm.ajax_url,{action:'wcapm_bulk_price_update',nonce:wcapm.nonce,update_type:$('#wcapm-update-type').val(),value:$('#wcapm-update-value').val(),price_type:$('#wcapm-price-type').val()},function(res){
            var $r=$('#wcapm-bulk-result').show().removeClass('success error');
            res.success?$r.addClass('success').text('Updated '+res.data.updated+' products.'):$r.addClass('error').text('Error.');
            $btn.prop('disabled',false).text('Apply to All Products');
        });
    });
    $('#wcapm-run-import').on('click',function(){
        var file=$('#wcapm-import-file')[0].files[0];
        if(!file){alert('Select a CSV first.');return;}
        var fd=new FormData();fd.append('action','wcapm_import_products');fd.append('nonce',wcapm.nonce);fd.append('csv_file',file);
        $.ajax({url:wcapm.ajax_url,type:'POST',data:fd,processData:false,contentType:false,success:function(res){
            var $r=$('#wcapm-import-result').show().removeClass('success error');
            res.success?$r.addClass('success').text('Created: '+res.data.created+', Updated: '+res.data.updated):$r.addClass('error').text('Import failed.');
        }});
    });
});
