(function ($) {
	'use strict';

	$(function () {
		const $body = $('#wsc-tiers-body');
		if (!$body.length) {
			return;
		}

		let index = $body.find('tr').length;

		$('#wsc-add-tier').on('click', function () {
			const row = `
				<tr>
					<td><input type="number" step="0.01" min="0" name="wsc_settings[tiers][${index}][threshold]" value="" /></td>
					<td><input type="number" step="0.01" min="0" max="100" name="wsc_settings[tiers][${index}][percent]" value="" /> %</td>
					<td><input type="text" class="regular-text" name="wsc_settings[tiers][${index}][label]" value="" /></td>
					<td><button type="button" class="button wsc-remove-tier">×</button></td>
				</tr>
			`;
			$body.append(row);
			index++;
		});

		$body.on('click', '.wsc-remove-tier', function (e) {
			e.preventDefault();
			$(this).closest('tr').remove();
		});
	});

})(jQuery);