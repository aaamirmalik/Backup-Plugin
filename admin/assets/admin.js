(function ($) {
	'use strict';

	$(document).on('click', '.notice.is-dismissible .notice-dismiss', function () {
		$(this).closest('.notice').fadeOut(120);
	});
})(jQuery);
