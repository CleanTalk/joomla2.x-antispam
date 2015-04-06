var close_animate=true;
function animate_banner(to)
{
	if(close_animate)
	{
		if(to==0.3)
		{
			jQuery('#feedback_notice').fadeTo(300,to,function(){
				animate_banner(1)
			});
		}
		else
		{
			jQuery('#feedback_notice').fadeTo(300,to,function(){
				animate_banner(0.3)
			});
		}
	}
}
jQuery(document).ready(function(){
	jQuery('#cleantalk_manual_key').attr('href', 'https://cleantalk.org/register?platform=joomla15&email=' + cleantalk_mail + '&website=' + cleantalk_domain);
	var ct_auth_key=jQuery('.cleantalk_auth_key').prop('value');
	setTimeout(function(){jQuery('#jform_params_reg_notice-lbl').html(ct_register_notice);},500);
	
	if(ct_joom25)
	{
		jQuery('#jform_params_autokey-lbl').append('<img border="0" align="" src="../plugins/system/antispambycleantalk/preloader.png" id="ct_preloader" style="float:right;margin:0px;margin-top:-3px;display:none;"/>');
		if(jQuery('#system-message').length==0)
		{
			jQuery('#system-message-container').append('<dl id="system-message"></dl>');
		}
		
		if(ct_show_feedback)
		{
			jQuery('#system-message').prepend('<dt class="notice">Error</dt><dd class="notice message" id="feedback_notice"><a href="#" style="font-size:15px;float:right;margin:6px;text-decoration:none;" id="feedback_notice_close">X</a><ul><li style="text-align:center;">'+ct_show_feedback_mes+'</li></ul></dd>');
		}
	}
	else
	{
		jQuery('#jform_params_autokey-lbl').append('<img border="0" align="" src="../plugins/system/antispambycleantalk/preloader.png" id="ct_preloader" style="float:right;margin:0px;margin-top:3px;display:none;"/>');
		if(ct_show_feedback)
		{
			jQuery('#system-message-container').prepend('<div class="alert alert-notice" style="text-align:center;padding-right:10px;" id="feedback_notice"><a href="#" style="font-size:15px;float:right;text-decoration:none;" id="feedback_notice_close">X</a><p style="margin-top:8px;">'+ct_show_feedback_mes+'</p></div>');
		}
	}
	
	jQuery('#feedback_notice_close').click(function(){
		var data = {
			'ct_delete_notice': 'yes'
		};		
		jQuery.ajax({
			type: "POST",
			url: location.href,
			data: data,
			success: function(msg){
				//alert(msg);
				close_animate=false;
				jQuery('#feedback_notice').hide();
			}
		});
	});
	jQuery('#feedback_notice_close').click(function(){
		animate_banner(0.3);
	});
	
	
	
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