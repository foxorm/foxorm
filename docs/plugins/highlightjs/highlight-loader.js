$js(['jquery','plugins/highlightjs/highlight.js'],function(){
	hljs.configure({useBR: true});
	var listLanguages = [];
	$('code').each(function(i, block){
		var s = $(block).attr('class').split(' ');
		var lang;
		for(var i in s){
			if(s[i].indexOf('lang-')===0){
				lang = s[i].substr(5);
				break;
			}
		}
		var load = function(){
			hljs.highlightBlock(block);

		};
		if(lang){
			if(hljs.listLanguages().indexOf(lang)===-1
				&&listLanguages.indexOf(lang)===-1){
				listLanguages.push(lang);
				$.get('plugins/highlightjs/languages/'+lang+'.js',function(func){
					eval('hljs.registerLanguage(lang,'+func+');');
					load();
				},'text');
			}
			else{
				if(hljs.listLanguages().indexOf(lang)===-1){
					var retry = function(){
						if(hljs.listLanguages().indexOf(lang)===-1){
							setTimeout(retry,1000);
						}
						else{
							load();
						}
					};
					setTimeout(retry,1000);
				}
				else{
					load();
				}
			}
		}
	});
});