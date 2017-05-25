var close_animate=true;

function ct_getCookie(name) {
  var matches = document.cookie.match(new RegExp(
    "(?:^|; )" + name.replace(/([\.$?*|{}\(\)\[\]\\\/\+^])/g, '\\$1') + "=([^;]*)"
  ));
  return matches ? decodeURIComponent(matches[1]) : undefined;
}

function ct_setCookie(name, value){
	var domain=location.hostname;
	tmp=domain.split('.');
	if(tmp[0].toLowerCase()=='www')
		tmp[0]='';
	else
		tmp[0]='.'+tmp[0];
	domain=tmp.join('.');
	
	document.cookie = name+" =; expires=Thu, 01 Jan 1970 00:00:01 GMT; path = /";
	document.cookie = name+" =; expires=Thu, 01 Jan 1970 00:00:01 GMT";
	document.cookie = name+" =; expires=Thu, 01 Jan 1970 00:00:01 GMT; path = /; domain = " +  domain;
	
	var date = new Date;
	date.setDate(date.getDate() + 365);
	setTimeout(function() { document.cookie = name+"=" + value + "; expires=" + date.toUTCString() + "; path = /;"}, 200)
}

function animate_banner(to){
		if(close_animate){
			jQuery('#feedback_notice').fadeTo(300,to);
	}
}

jQuery(document).ready(function(){
	
	var ct_auth_key = jQuery('.cleantalk_auth_key').prop('value'),
		ct_notice_cookie = ct_getCookie('ct_notice_cookie');
	
	// Viewing button to access CP
	if(ct_key_is_ok == 1){
		
		jQuery("a[href='index.php?option=com_plugins&view=plugins&filter_search=cleantalk']").parents('.alert-info').hide();
		
		if(ct_moderate_ip == 0)
			jQuery('#jform_params_apikey').css('border-bottom', '2px solid green')
				.parent()
				.append("<p class='ct_status_label green'>"+ct_key_is_ok_notice+"</p>");
		
		if(ct_user_token)
			jQuery('.cleantalk_key_control')
				.parent().parent()
				.html('')
				.append("<div id='key_buttons_wrapper'></div>").children()
					.append("<a target='_blank'></a>").children('a')
						.attr('href', 'https://cleantalk.org/my?user_token='+ct_user_token)
						.append("<button class='key_buttons' id='ct_cp_button' type='button'>"+ct_statlink_label+"</button>");
							
	// Viewing buttons to get key
	}else{
		
		if(ct_moderate_ip == 0){
			jQuery('#jform_params_apikey').css('border-bottom', '2px solid red')
				.parent()
				.append("<p class='ct_status_label red'>"+ct_key_is_bad_notice+"</p>");
			
			jQuery('.cleantalk_key_control')
				.parent().parent()
				.html('')
				.append("<div id='key_buttons_wrapper'></div>").children()
					.append("<button class='key_buttons' id='ct_auto_button' type='button'>"+ct_autokey_label+"</button>")
					.append("<img class='display_none' id='ct_preloader' src='../plugins/system/antispambycleantalk/preloader.gif' />")
					.append("<a target='_blank'></a>").children('a')
						.attr('href', 'https://cleantalk.org/register?platform=joomla3&email=' + cleantalk_mail + '&website=' + cleantalk_domain)
						.append("<button class='key_buttons' id='ct_manual_button' type='button'>"+ct_manualkey_label+"</button>").parents('#key_buttons_wrapper')
					.append("<p id='ct_email_warning'>"+ct_key_notice1+cleantalk_mail+ct_key_notice2+"</p>")
					.append("<br>")
					.append("<a id='ct_license_agreement' href='https://cleantalk.org/publicoffer' target='_blank'>"+ct_license_notice+"</a>");
		}
	}
	
	// Appereance fix
	if(!ct_joom25){
		jQuery('#key_buttons_wrapper').parents('.control-group').css('margin-bottom', 0);
		jQuery('#ct_preloader').css('margin', '-7px 8px 0 0');
	}
	
	// Unknown
	if(ct_joom25){
		
		if(jQuery('#system-message').length==0)
			jQuery('#system-message-container').append('<dl id="system-message"></dl>');
		
		if(ct_show_feedback && ct_notice_cookie == undefined && !ct_notice_review_done)
			jQuery('#system-message').prepend('<dt class="notice">Error</dt><dd class="notice message" id="feedback_notice"><a href="#" style="font-size:15px;float:right;margin:6px;text-decoration:none;" id="feedback_notice_close">X</a><ul><li style="text-align:center;">'+ct_show_feedback_mes+'</li></ul></dd>');
		
	}else{
		
		if(ct_show_feedback && ct_notice_cookie == undefined && !ct_notice_review_done)
			jQuery('#system-message-container').prepend('<div class="alert alert-notice" style="text-align:center;padding-right:10px;" id="feedback_notice"><a href="#" style="font-size:15px;float:right;text-decoration:none;" id="feedback_notice_close">X</a><p style="margin-top:8px;">'+ct_show_feedback_mes+'</p></div>');
		
	}
		
	// Notice for moderate IP
	if(ct_moderate_ip == 1){
		
		if(ct_joom25)
			jQuery('#jform_params_apikey').parent().append("<br /><h4>The anti-spam service is paid by your hosting provider. License #"+ct_ip_license+"</h4>");
		else
			jQuery('#jform_params_apikey').parent().parent().append("<br /><h4>The anti-spam service is paid by your hosting provider. License #"+ct_ip_license+"</h4>");
		
	}
	
	// Handler for 
	jQuery('#ct_review_link').click(function(){
		var data = {
			'ct_delete_notice': 'yes'
		};
		ct_setCookie('ct_notice_cookie', '1');
		jQuery.ajax({
			type: "POST",
			url: location.href,
			data: data,
			success: function(msg){
				close_animate = false;
				jQuery('#feedback_notice').hide();
			}
		});
	});
	
	// Handler for closing banner
	jQuery('#feedback_notice_close').click(function(){
		animate_banner(0);
		ct_setCookie('ct_notice_cookie', '1');
		setTimeout(function(){
			close_animate = false;
			jQuery('#feedback_notice_close').parent().hide();
			},
		500);
	});
		
	// Handler for get_auto_key button
	jQuery('#ct_auto_button').click(function(){
		
		var data = {
			'get_auto_key': 'yes'
		};
		jQuery('#ct_preloader').show();
		jQuery.ajax({
			type: "POST",
			url: location.href,
			data: data,
			// dataType: 'json',
			success: function(msg){
				msg=jQuery.parseJSON(msg);
				if(msg.error_message){
					
					//Showing error banner
					if(ct_joom25)
						jQuery('#system-message').prepend('<dt class="notice">Error</dt><dd class="error message"><ul><li>'+msg.error_message+' ' + ct_register_error + '</li></ul></dd>');
					else
						jQuery('#system-message-container').prepend('<button type="button" class="close" data-dismiss="alert">×</button><div class="alert alert-error"><h4 class="alert-heading">Error</h4><p>'+msg.error_message+'<br />'+ct_register_error+'</p></div></div>');
					
					jQuery('#ct_preloader').hide();
					
				}else if(msg.auth_key){
										
					jQuery('.cleantalk_auth_key').val(msg.auth_key);
					jQuery('#jform_params_user_token').val(msg.user_token);
					
					//Showing the banner
					if(ct_joom25)
						jQuery('#system-message').prepend('<dt class="notice">Notice</dt><dd class="info message"><ul><li>'+ct_register_message+'</li></ul></dd>');
					else
						jQuery('#system-message-container').prepend('<button type="button" class="close" data-dismiss="alert">×</button><div class="alert alert-success"><h4 class="alert-heading">Success!</h4><p>'+ct_register_message+'</p></div></div>');
					
					setTimeout(function(){
						jQuery('#ct_preloader').hide();
						Joomla.submitbutton('plugin.apply');
					}, 3000);
				}
			}
		});
	});
});
