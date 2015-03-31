jQuery(document).ready(function(){
	jQuery('#cleantalk_manual_key').attr('href', 'https://cleantalk.org/register?platform=joomla15&email=' + cleantalk_mail + '&website=' + cleantalk_domain);
	var ct_auth_key=jQuery('.cleantalk_auth_key').prop('value');
	setTimeout(function(){jQuery('#jform_params_reg_notice-lbl').html(ct_register_notice);},500);
	
	if(ct_joom25)
	{
		jQuery('#jform_params_autokey-lbl').append('<img border="0" align="" src="../plugins/system/antispambycleantalk/preloader.png" id="ct_preloader" style="float:right;margin:0px;margin-top:-3px;display:none;"/>');
	}
	else
	{
		jQuery('#jform_params_autokey-lbl').append('<img border="0" align="" src="../plugins/system/antispambycleantalk/preloader.png" id="ct_preloader" style="float:right;margin:0px;margin-top:3px;display:none;"/>');
	}
	
	if(ct_auth_key!=''&&ct_auth_key!='enter key')
	{
		if(ct_joom25)
		{
			jQuery('.cleantalk_auto_key').parent().parent().hide();
			jQuery('.cleantalk_notice').parent().parent().hide();
			jQuery('#jform_params_reg_notice-lbl').parent().parent().parent().hide();
		}
		else
		{
			jQuery('.cleantalk_auto_key').parent().parent().parent().hide();
			jQuery('.cleantalk_notice').parent().parent().parent().hide();
			jQuery('#jform_params_reg_notice-lbl').parent().parent().parent().parent().hide();
		}
		jQuery('#cleantalk_manual_key').attr('href', 'https://cleantalk.org/my?user_token='+ct_user_token);
		jQuery('#cleantalk_manual_key').html(ct_stat_link);
	}
	jQuery('.cleantalk_auto_key').click(function(){
		var data = {
			'get_auto_key': 'yes'
		};
		jQuery('#ct_preloader').show();
		jQuery.ajax({
			type: "POST",
			url: location.href,
			data: data,
			//dataType: 'json',
			success: function(msg){
				msg=jQuery.parseJSON(msg);
				if(msg.error_message)
				{
					if(ct_joom25)
					{
						jQuery('#system-message').prepend('<dt class="notice">Error</dt><dd class="error message"><ul><li>'+msg.error_message+' ' + ct_register_error + '</li></ul></dd>');
					}
					else
					{
						jQuery('#system-message-container').prepend('<button type="button" class="close" data-dismiss="alert">×</button><div class="alert alert-error"><h4 class="alert-heading">Error</h4><p>'+msg.error_message+'<br />'+ct_register_error+'</p></div></div>');
					}
					jQuery('#ct_preloader').hide();
				}
				else if(msg.auth_key)
				{
					jQuery('.cleantalk_auth_key').val(msg.auth_key);
					if(ct_joom25)
					{
						jQuery('#system-message').prepend('<dt class="notice">Notice</dt><dd class="info message"><ul><li>'+ct_register_message+'</li></ul></dd>');
					}
					else
					{
						jQuery('#system-message-container').prepend('<button type="button" class="close" data-dismiss="alert">×</button><div class="alert alert-success"><h4 class="alert-heading">Success!</h4><p>'+ct_register_message+'</p></div></div>');
					}
					if(msg.user_token)
					{
						jQuery('#jform_params_user_token').val(msg.user_token);
					}
					setTimeout(function(){jQuery('#ct_preloader').hide();Joomla.submitbutton('plugin.apply');},2000);
				}
			}
		});
	});
});