/**
 * Catalog AI — Admin Scripts.
 */
(function ($) {
	'use strict';

	const { restUrl, nonce, i18n, costPerImage } = window.catalogAi || {};

	const COST_MAP = costPerImage || { try_on: 0.05, recontext: 0.06 };

	/**
	 * Product card selection.
	 */
	$('.catalog-ai-product-card').on('click', function () {
		$(this).toggleClass('selected');
		syncSelectedProducts();
	});

	$('#catalog-ai-select-all').on('click', function () {
		$('.catalog-ai-product-card').addClass('selected');
		syncSelectedProducts();
	});

	$('#catalog-ai-deselect-all').on('click', function () {
		$('.catalog-ai-product-card').removeClass('selected');
		syncSelectedProducts();
	});

	function syncSelectedProducts() {
		const ids = [];
		$('.catalog-ai-product-card.selected').each(function () {
			ids.push($(this).data('product-id'));
		});
		$('#catalog-ai-products').val(ids.join(','));
		$('#catalog-ai-selected-num').text(ids.length);
		updateCostEstimate();
	}

	function getSelectedProductIds() {
		const val = $('#catalog-ai-products').val();
		if (!val) return [];
		return val.split(',').map(Number).filter(Boolean);
	}

	/**
	 * Submit generation form.
	 */
	$('#catalog-ai-generate-form').on('submit', function (e) {
		e.preventDefault();

		const $btn    = $('#catalog-ai-submit');
		const $status = $('#catalog-ai-status');

		const productIds = getSelectedProductIds();
		if (productIds.length === 0) {
			$status.text('Please select at least one product.');
			return;
		}

		const personImageId = $('#catalog-ai-person-image-id').val();
		const data = {
			product_ids:     productIds,
			mode:            $('#catalog-ai-mode').val(),
			scene_prompt:    $('#catalog-ai-scene').val(),
			person_image_id: personImageId ? Number(personImageId) : 0,
			target:          $('#catalog-ai-target').val(),
		};

		$btn.prop('disabled', true);
		$status.text(i18n.generating);

		// Use batch endpoint for multiple products, single for one.
		const endpoint = data.product_ids.length > 1
			? restUrl + '/generate/batch'
			: restUrl + '/generate';

		// For single product, flatten the data.
		const payload = data.product_ids.length > 1
			? data
			: { ...data, product_id: data.product_ids[0] };

		wp.apiFetch({
			path: endpoint.replace(restUrl, '/catalog-ai/v1'),
			method: 'POST',
			data: payload,
		}).then(function (response) {
			$status
				.text(i18n.queued + (response.batch_id ? ' — Batch: ' + response.batch_id : ''))
				.removeClass()
				.addClass('description catalog-ai-status-pending');
			$btn.prop('disabled', false);
			refreshJobs();
		}).catch(function (error) {
			$status
				.text(i18n.failed + ': ' + (error.message || 'Unknown error'))
				.removeClass()
				.addClass('description catalog-ai-status-failed');
			$btn.prop('disabled', false);
		});
	});

	/**
	 * Toggle fields based on mode + update cost estimate.
	 */
	$('#catalog-ai-mode').on('change', function () {
		const mode = $(this).val();
		$('#catalog-ai-scene-row').toggle(mode === 'recontext' || mode === 'bgswap');
		$('#catalog-ai-person-row').toggle(mode === 'try_on');
		updateCostEstimate();
	}).trigger('change');

	/**
	 * Person / Model image picker using WP Media Library.
	 */
	let personFrame;

	$('#catalog-ai-person-select').on('click', function (e) {
		e.preventDefault();

		if (personFrame) {
			personFrame.open();
			return;
		}

		personFrame = wp.media({
			title: 'Select Person / Model Image',
			button: { text: 'Use This Image' },
			multiple: false,
			library: { type: 'image' },
		});

		personFrame.on('select', function () {
			const attachment = personFrame.state().get('selection').first().toJSON();
			$('#catalog-ai-person-image-id').val(attachment.id);
			$('#catalog-ai-person-preview').html(
				'<img src="' + (attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url) +
				'" style="max-width:150px; max-height:150px; border:1px solid #ddd; border-radius:4px;" />'
			);
			$('#catalog-ai-person-remove').show();
		});

		personFrame.open();
	});

	$('#catalog-ai-person-remove').on('click', function (e) {
		e.preventDefault();
		$('#catalog-ai-person-image-id').val('');
		$('#catalog-ai-person-preview').empty();
		$(this).hide();
	});

	/**
	 * Update the live cost estimate based on current selections.
	 */
	function updateCostEstimate() {
		const mode = $('#catalog-ai-mode').val();
		const count = getSelectedProductIds().length;
		const perImage = COST_MAP[mode] || 0.06;
		const total = (perImage * count).toFixed(2);

		const $el = $('#catalog-ai-estimate-result');
		if (count === 0) {
			$el.text('Select products to see cost estimate.');
		} else {
			$el.html(
				'<strong>' + count + ' product' + (count > 1 ? 's' : '') + '</strong> &times; $' +
				perImage.toFixed(2) + ' (' + ({try_on: 'Virtual Try-On', bgswap: 'Background Swap', recontext: 'Recontextualization'}[mode] || mode) +
				') = <strong>$' + total + '</strong> estimated'
			);
		}
	}

	/**
	 * Refresh the jobs list.
	 */
	function refreshJobs() {
		wp.apiFetch({
			path: '/catalog-ai/v1/status',
		}).then(function (status) {
			$('#catalog-ai-jobs').html(
				'<p class="description">' +
				'Plugin v' + status.version +
				' | Configured: ' + (status.configured ? 'Yes' : 'No') +
				' | Action Scheduler: ' + (status.action_scheduler ? 'Active' : 'Missing') +
				'</p>'
			);
		});
	}

	/**
	 * Fetch and render usage stats.
	 */
	function refreshUsage() {
		wp.apiFetch({
			path: '/catalog-ai/v1/usage',
		}).then(function (data) {
			const tryOn = data.breakdown.try_on || { count: 0, cost: 0 };
			const recon = data.breakdown.recontext || { count: 0, cost: 0 };

			$('#catalog-ai-usage').html(
				'<table class="widefat striped">' +
				'<thead><tr>' +
				'<th>Mode</th><th>Images</th><th>Cost</th>' +
				'</tr></thead><tbody>' +
				'<tr><td>Try-On</td><td>' + tryOn.count + '</td><td>$' + tryOn.cost.toFixed(2) + '</td></tr>' +
				'<tr><td>Recontext</td><td>' + recon.count + '</td><td>$' + recon.cost.toFixed(2) + '</td></tr>' +
				'<tr style="font-weight:bold;"><td>Total</td><td>' +
				data.total_images + '</td><td>$' + data.total_cost.toFixed(2) + '</td></tr>' +
				'</tbody></table>' +
				'<p class="description" style="margin-top:8px;">' + data.current_month + ' &mdash; estimates only.</p>'
			);
		}).catch(function () {
			$('#catalog-ai-usage').html('<p class="description">Unable to load usage data.</p>');
		});
	}

	// Initial load.
	if (typeof wp !== 'undefined' && wp.apiFetch) {
		refreshJobs();
		refreshUsage();
		updateCostEstimate();
	}

})(jQuery);
