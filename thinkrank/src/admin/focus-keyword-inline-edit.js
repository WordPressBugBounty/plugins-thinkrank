/**
 * Focus Keyword Inline Edit
 *
 * Handles inline editing of focus keywords in post list tables
 *
 * @param $
 * @package
 * @since 1.0.0
 */

(function ($) {
	'use strict';

	// Wait for DOM to be ready
	$(document).ready(function () {
		initFocusKeywordInlineEdit();
	});

	/**
	 * Initialize focus keyword inline editing
	 */
	function initFocusKeywordInlineEdit() {
		// Check if we have the necessary data
		if (typeof thinkrankFocusKeyword === 'undefined') {
			return;
		}

		// Handle click on focus keyword display
		$(document).on(
			'click',
			'.thinkrank-focus-keyword-display',
			function (e) {
				e.preventDefault();
				e.stopPropagation();

				const $display = $(this);
				const $item = $display.closest('.thinkrank-focus-keyword-item');

				// Don't allow editing if already editing
				if ($item.hasClass('is-editing')) {
					return;
				}

				startEditing($item, $display);
			}
		);

		// Handle click outside to cancel editing
		// $(document).on('click', function(e) {
		//     const $target = $(e.target);
		//
		//     // If clicking outside of an editing item, cancel editing
		//     if (!$target.closest('.thinkrank-focus-keyword-item.is-editing').length) {
		//         $('.thinkrank-focus-keyword-item.is-editing').each(function() {
		//             cancelEditing($(this));
		//         });
		//     }
		// });
	}

	/**
	 * Start editing a focus keyword
	 *
	 * @param {jQuery} $item    The item container
	 * @param {jQuery} $display The display element
	 */
	function startEditing($item, $display) {
		const currentKeyword = $display.data('keyword') || '';
		const postId = $item.data('post-id');

		// Create input field
		const $input = $('<input>', {
			type: 'text',
			class: 'thinkrank-focus-keyword-input',
			value: currentKeyword,
			placeholder: thinkrankFocusKeyword.i18n.notSet,
			title: thinkrankFocusKeyword.i18n.pressEnter,
		});

		// Add editing class
		$item.addClass('is-editing');

		// Insert input after display element
		$display.after($input);

		// Focus input and select text
		$input.focus().select();

		// Handle Enter key to save
		$input.on('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				saveFocusKeyword($item, $input, $display);
			} else if (e.key === 'Escape') {
				e.preventDefault();
				cancelEditing($item);
			}
		});

		// Handle blur to save
		$input.on('blur', function () {
			// Small delay to allow click events to fire first
			setTimeout(function () {
				if (
					$item.hasClass('is-editing') &&
					!$item.hasClass('is-saving')
				) {
					saveFocusKeyword($item, $input, $display);
				}
			}, 200);
		});
	}

	/**
	 * Cancel editing
	 *
	 * @param {jQuery} $item The item container
	 */
	function cancelEditing($item) {
		$item.removeClass('is-editing is-saving');
		$item.find('.thinkrank-focus-keyword-input').remove();
		$item.find('.thinkrank-focus-keyword-loading').hide();
	}

	/**
	 * Save focus keyword via AJAX
	 *
	 * @param {jQuery} $item    The item container
	 * @param {jQuery} $input   The input element
	 * @param {jQuery} $display The display element
	 */
	function saveFocusKeyword($item, $input, $display) {
		const newKeyword = $input.val().trim();
		const oldKeyword = $display.data('keyword') || '';
		const postId = $item.data('post-id');

		// If no change, just cancel editing
		if (newKeyword === oldKeyword) {
			cancelEditing($item);
			return;
		}

		// Show loading state
		$item.addClass('is-saving');
		$item.find('.thinkrank-focus-keyword-loading').show();
		$input.prop('disabled', true);

		// Send AJAX request
		$.ajax({
			url: thinkrankFocusKeyword.ajaxUrl,
			type: 'POST',
			data: {
				action: thinkrankFocusKeyword.action,
				nonce: thinkrankFocusKeyword.nonce,
				post_id: postId,
				focus_keyword: newKeyword,
			},
			success(response) {
				if (response.success) {
					// Update display
					updateDisplay($item, $display, response.data);

					// Show success feedback briefly
					showFeedback($item, 'success');
				} else {
					// Show error message
					showError(
						$item,
						response.data.message ||
							thinkrankFocusKeyword.i18n.error
					);

					// Re-enable input for retry
					$input.prop('disabled', false).focus();
				}
			},
			error(xhr, status, error) {
				// Show error message
				showError($item, thinkrankFocusKeyword.i18n.error);

				// Re-enable input for retry
				$input.prop('disabled', false).focus();
			},
			complete() {
				$item.removeClass('is-saving');
				$item.find('.thinkrank-focus-keyword-loading').hide();
			},
		});
	}

	/**
	 * Update display after successful save
	 *
	 * @param {jQuery} $item    The item container
	 * @param {jQuery} $display The display element
	 * @param {Object} data     Response data
	 */
	function updateDisplay($item, $display, data) {
		// Update data attribute
		$display.data('keyword', data.focus_keyword);

		// Update display text
		$display.text(data.display_value);

		// Update CSS classes
		if (data.has_keyword) {
			$display.removeClass('thinkrank-not-set').addClass('thinkrank-set');
		} else {
			$display.removeClass('thinkrank-set').addClass('thinkrank-not-set');
		}

		// Remove editing state
		cancelEditing($item);
	}

	/**
	 * Show feedback message
	 *
	 * @param {jQuery} $item The item container
	 * @param {string} type  Feedback type (success or error)
	 */
	function showFeedback($item, type) {
		const $display = $item.find('.thinkrank-focus-keyword-display');

		// Add feedback class
		$display.addClass('thinkrank-feedback-' + type);

		// Remove feedback class after animation
		setTimeout(function () {
			$display.removeClass('thinkrank-feedback-' + type);
		}, 1000);
	}

	/**
	 * Show error message
	 *
	 * @param {jQuery} $item   The item container
	 * @param {string} message Error message
	 */
	function showError($item, message) {
		// Create error notice
		const $error = $('<div>', {
			class: 'thinkrank-inline-error',
			text: message,
		});

		// Insert error after item
		$item.after($error);

		// Remove error after 3 seconds
		setTimeout(function () {
			$error.fadeOut(function () {
				$error.remove();
			});
		}, 3000);

		// Show error feedback
		showFeedback($item, 'error');
	}
})(jQuery);
