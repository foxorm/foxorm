$js('jquery',function() {
	var scroller = $('html, body');
	var speed = 750;
    $('.page-scroll').on('click', function(e){
		e.preventDefault();
		var href = $(this).attr('href') || '#';
		href = href.substr(href.indexOf('#'));
		var top;
		if(href=='#'){
			top = 0;
		}
		else{
			top =  $(href).offset().top;
		}
		scroller.stop(true,true).animate({
			scrollTop: top 
		}, speed );
		return false;
	});
	
});