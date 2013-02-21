var Searef;
(function($){
	Searef = {
		sortable:function(s){
			sortable(s);
		}
	}
	function sortable(s){
		$(s).sortable();
		$(s + ' .term-title').css('cursor','move');
	}	
})(jQuery);
