var Searef;
(function($){
	Searef = {
		sortable:function(){
			sortable('.sortable-terms');
		}
	}
	function sortable(s){
		$(s).sortable();
		$(s + ' .term-title').css('cursor','move');
	}	
})(jQuery);
