var close_animate=true;
function ct_getCookie(name) {
  var matches = document.cookie.match(new RegExp(
    "(?:^|; )" + name.replace(/([\.$?*|{}\(\)\[\]\\\/\+^])/g, '\\$1') + "=([^;]*)"
  ));
  return matches ? decodeURIComponent(matches[1]) : undefined;
}
function ct_setCookie(name, value)
{
	var domain=location.hostname;
	tmp=domain.split('.');
	if(tmp[0].toLowerCase()=='www')
	{
		tmp[0]='';
	}
	else
	{
		tmp[0]='.'+tmp[0];
	}
	domain=tmp.join('.');
	
	document.cookie = name+" =; expires=Thu, 01 Jan 1970 00:00:01 GMT; path = /";
	document.cookie = name+" =; expires=Thu, 01 Jan 1970 00:00:01 GMT";
	document.cookie = name+" =; expires=Thu, 01 Jan 1970 00:00:01 GMT; path = /; domain = " +  domain;
	
	var date = new Date;
	date.setDate(date.getDate() + 365);
	setTimeout(function() { document.cookie = name+"=" + value + "; expires=" + date.toUTCString() + "; path = /;"}, 200)
}
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
		
		var ct_notice_cookie=ct_getCookie('ct_notice_cookie');
		
		if(ct_show_feedback&&ct_notice_cookie==undefined)
		{
			jQuery('#system-message').prepend('<dt class="notice">Error</dt><dd class="notice message" id="feedback_notice"><a href="#" style="font-size:15px;float:right;margin:6px;text-decoration:none;" id="feedback_notice_close">X</a><ul><li style="text-align:center;">'+ct_show_feedback_mes+'</li></ul></dd>');
		}
	}
	else
	{
		jQuery('#jform_params_autokey-lbl').append('<img border="0" align="" src="../plugins/system/antispambycleantalk/preloader.png" id="ct_preloader" style="float:right;margin:0px;margin-top:3px;display:none;"/>');
		var ct_notice_cookie=ct_getCookie('ct_notice_cookie');
		
		if(ct_show_feedback&&ct_notice_cookie==undefined)
		{
			jQuery('#system-message-container').prepend('<div class="alert alert-notice" style="text-align:center;padding-right:10px;" id="feedback_notice"><a href="#" style="font-size:15px;float:right;text-decoration:none;" id="feedback_notice_close">X</a><p style="margin-top:8px;">'+ct_show_feedback_mes+'</p></div>');
		}
	}
	
	jQuery('#feedback_notice_close').click(function(){
		var data = {
			'ct_delete_notice': 'yes'
		};
		ct_setCookie('ct_notice_cookie', '1');
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
		ct_setCookie('ct_notice_cookie', '1');
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
	
	if(ct_moderate_ip == 1)
	{
		if(ct_joom25)
		{
			jQuery('#jform_params_apikey').parent().html("The anti-spam service is paid by your hosting provider. License #"+ct_ip_license);
		}
		else
		{
			jQuery('#jform_params_apikey').parent().parent().html("The anti-spam service is paid by your hosting provider. License #"+ct_ip_license);
		}
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