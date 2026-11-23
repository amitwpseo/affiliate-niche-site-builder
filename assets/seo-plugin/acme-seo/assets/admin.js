/* Site SEO admin: site audit runner (a few pages per request so nothing times out). */
(function ($) {
	'use strict';
	$(document).on('click', '#acme-audit-run', function () {
		var $b = $(this), $s = $('#acme-audit-status'), nonce = $b.data('nonce');
		$b.prop('disabled', true);
		$s.text('Starting...');
		function fail(msg) {
			$s.text(msg);
			$b.prop('disabled', false);
		}
		function step(offset) {
			$.post(acmeSeo.ajax, { action: 'acme_seo_audit_batch', nonce: nonce, offset: offset }).done(function (r) {
				if (!r || !r.success) {
					return fail('The audit stopped: ' + ((r && r.data) || 'unknown error'));
				}
				var d = r.data;
				$s.text('Checked ' + Math.min(d.next, d.total) + ' of ' + d.total + ' pages...');
				if (d.done) {
					window.location.reload();
				} else {
					step(d.next);
				}
			}).fail(function () {
				fail('A request failed. Press the button again.');
			});
		}
		$.post(acmeSeo.ajax, { action: 'acme_seo_audit_start', nonce: nonce }).done(function (r) {
			if (!r || !r.success) {
				return fail('Could not start the audit.');
			}
			step(0);
		}).fail(function () {
			fail('Could not start the audit.');
		});
	});
})(jQuery);
