<?php
/**
 * Shared admin UI component renderer.
 *
 * @package MpAdminDesignSystem
 */

declare(strict_types=1);

namespace MPCF\Vendor\Mpds;

/**
 * Renders reusable `mpcf-ui-*` admin design-system components.
 *
 * Every method returns an escaped HTML string; nothing is echoed directly, so
 * callers control output timing and can compose components freely. This
 * class has no constructor dependencies — it is stateless and safe to
 * instantiate once per request or per call.
 *
 * Extracted from Universal Multicurrency's `umc-ui-*` component set (the
 * origin implementation) and Universal Geo Context's `ugc-ui-*` copy. Two
 * fixes were made on the way out: the legacy dual `umc-display-*` /
 * `ugc-display-*` class emission (a UMC-specific migration artifact) is
 * dropped, and the app-specific "Display compatibility" helpers
 * (`segmented_control`, `callout`, UMC's currency-switcher legacy markup)
 * were not carried over — they were never general-purpose components.
 */
final class ComponentRenderer {

	/**
	 * Valid status badge variants.
	 *
	 * @var list<string>
	 */
	public const BADGE_VARIANTS = array(
		'active',
		'warning',
		'error',
		'disabled',
		'recommended',
		'available',
		'missing',
		'misconfigured',
	);

	/**
	 * Renders a sub-page introduction block.
	 *
	 * @param string $title       Visible title.
	 * @param string $description Supporting description.
	 */
	public function page_intro( string $title, string $description = '' ): string {
		$description_html = '' !== $description
			? sprintf( '<p class="mpcf-ui-page-intro__description">%s</p>', esc_html( $description ) )
			: '';

		return sprintf(
			'<header class="mpcf-ui-page-intro"><h3 class="mpcf-ui-page-intro__title">%s</h3>%s</header>',
			esc_html( $title ),
			$description_html
		);
	}

	/**
	 * Opens a feature section landmark for grouping related cards.
	 *
	 * @param string $title       Section landmark title.
	 * @param string $description Optional supporting description.
	 */
	public function feature_section_open( string $title, string $description = '' ): string {
		$description_html = '' !== $description
			? sprintf( '<p class="mpcf-ui-feature-section__description">%s</p>', esc_html( $description ) )
			: '';

		return sprintf(
			'<section class="mpcf-ui-feature-section"><header class="mpcf-ui-feature-section__header"><h4 class="mpcf-ui-feature-section__title">%1$s</h4>%2$s</header><div class="mpcf-ui-feature-section__content">',
			esc_html( $title ),
			$description_html
		);
	}

	/**
	 * Closes a feature section landmark.
	 */
	public function feature_section_close(): string {
		return '</div></section>';
	}

	/**
	 * Opens a statistics card grid.
	 */
	public function statistics_grid_open(): string {
		return '<div class="mpcf-ui-statistics-grid">';
	}

	/**
	 * Closes a statistics card grid.
	 */
	public function statistics_grid_close(): string {
		return '</div>';
	}

	/**
	 * Renders one statistics card.
	 *
	 * @param string $label Metric label.
	 * @param string $value Metric value.
	 * @param string $hint  Optional supporting hint.
	 */
	public function statistics_card( string $label, string $value, string $hint = '' ): string {
		$hint_html = '' !== $hint
			? sprintf( '<span class="mpcf-ui-statistics-card__hint">%s</span>', esc_html( $hint ) )
			: '';

		return sprintf(
			'<div class="mpcf-ui-statistics-card"><span class="mpcf-ui-statistics-card__label">%1$s</span><strong class="mpcf-ui-statistics-card__value">%2$s</strong>%3$s</div>',
			esc_html( $label ),
			esc_html( $value ),
			$hint_html
		);
	}

	/**
	 * Opens a settings card.
	 *
	 * @param string $title       Card title.
	 * @param string $description Short description.
	 */
	public function settings_card_open( string $title, string $description = '' ): string {
		$description_html = '' !== $description
			? sprintf( '<p class="mpcf-ui-settings-card__description">%s</p>', esc_html( $description ) )
			: '';

		return sprintf(
			'<section class="mpcf-ui-settings-card"><header class="mpcf-ui-settings-card__header"><h4 class="mpcf-ui-settings-card__title">%1$s</h4>%2$s</header><div class="mpcf-ui-settings-card__divider" aria-hidden="true"></div><div class="mpcf-ui-settings-card__body">',
			esc_html( $title ),
			$description_html
		);
	}

	/**
	 * Renders an optional settings card footer.
	 *
	 * @param string $html Footer markup (escaped by caller when dynamic).
	 */
	public function settings_card_footer( string $html ): string {
		if ( '' === $html ) {
			return '';
		}

		return sprintf(
			'</div><footer class="mpcf-ui-settings-card__footer">%s</footer></section>',
			$html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Caller supplies escaped or static markup.
		);
	}

	/**
	 * Closes a settings card without a footer.
	 */
	public function settings_card_close(): string {
		return '</div></section>';
	}

	/**
	 * Opens a choice-card grid.
	 */
	public function choice_cards_open(): string {
		return '<div class="mpcf-ui-choice-cards">';
	}

	/**
	 * Closes a choice-card grid.
	 */
	public function choice_cards_close(): string {
		return '</div>';
	}

	/**
	 * Renders one visual choice card backed by a native radio input.
	 *
	 * @param string                $name         Input name.
	 * @param string                $value        Input value.
	 * @param bool                  $checked      Whether selected.
	 * @param string                $title        Visible title.
	 * @param string                $description  Supporting description.
	 * @param string                $diagram_html Optional decorative diagram markup.
	 * @param array<string, string> $attrs        Extra input attributes.
	 * @param string                $badge        Optional badge label.
	 * @param string                $note         Optional secondary note.
	 */
	public function choice_card(
		string $name,
		string $value,
		bool $checked,
		string $title,
		string $description = '',
		string $diagram_html = '',
		array $attrs = array(),
		string $badge = '',
		string $note = ''
	): string {
		$attr_html = $this->attr_html( $attrs );

		$description_html = '' !== $description
			? sprintf( '<span class="mpcf-ui-choice-card__description">%s</span>', esc_html( $description ) )
			: '';

		$badge_html = '' !== $badge
			? sprintf( '<span class="mpcf-ui-choice-card__badge">%s</span>', esc_html( $badge ) )
			: '';

		$note_html = '' !== $note
			? sprintf( '<span class="mpcf-ui-choice-card__note">%s</span>', esc_html( $note ) )
			: '';

		$diagram = '' !== $diagram_html
			? sprintf( '<span class="mpcf-ui-choice-card__diagram">%s</span>', $diagram_html ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static consumer-owned SVG fragments only.
			: '';

		return sprintf(
			'<label class="mpcf-ui-choice-card"><input type="radio" name="%1$s" value="%2$s"%3$s%4$s /><span class="mpcf-ui-choice-card__content"><span class="mpcf-ui-choice-card__title">%5$s</span>%6$s%7$s%8$s%9$s</span></label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $checked, true, false ),
			$attr_html,
			esc_html( $title ),
			$description_html,
			$badge_html,
			$note_html,
			$diagram
		);
	}

	/**
	 * Renders one toggle row backed by a native checkbox.
	 *
	 * @param string                $name        Input name.
	 * @param bool                  $checked     Whether checked.
	 * @param string                $label       Visible label.
	 * @param string                $description Optional description.
	 * @param array<string, string> $attrs       Extra input attributes.
	 */
	public function toggle_row(
		string $name,
		bool $checked,
		string $label,
		string $description = '',
		array $attrs = array()
	): string {
		$attr_html = $this->attr_html( $attrs );

		$description_html = '' !== $description
			? sprintf( '<span class="mpcf-ui-toggle-row__description">%s</span>', esc_html( $description ) )
			: '';

		return sprintf(
			'<label class="mpcf-ui-toggle-row"><input type="hidden" name="%1$s" value="0" /><input type="checkbox" name="%1$s" value="1"%2$s%3$s /><span class="mpcf-ui-toggle-row__label">%4$s</span>%5$s</label>',
			esc_attr( $name ),
			checked( $checked, true, false ),
			$attr_html,
			esc_html( $label ),
			$description_html
		);
	}

	/**
	 * Renders a select/dropdown row.
	 *
	 * @param string                $name        Field name.
	 * @param string                $label       Visible label.
	 * @param string                $description Optional description.
	 * @param string                $select_html Pre-built select element markup.
	 * @param array<string, string> $attrs       Extra attributes for the wrapper id.
	 */
	public function select_row(
		string $name,
		string $label,
		string $description,
		string $select_html,
		array $attrs = array()
	): string {
		$id = $attrs['id'] ?? sanitize_key( str_replace( array( '[', ']' ), array( '-', '' ), $name ) );

		$description_html = '' !== $description
			? sprintf( '<span class="mpcf-ui-field-row__description">%s</span>', esc_html( $description ) )
			: '';

		return sprintf(
			'<div class="mpcf-ui-field-row mpcf-ui-field-row--select"><label class="mpcf-ui-field-row__label" for="%1$s">%2$s</label>%3$s<div class="mpcf-ui-field-row__control">%4$s</div></div>',
			esc_attr( (string) $id ),
			esc_html( $label ),
			$description_html,
			$select_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by caller with escaped options.
		);
	}

	/**
	 * Renders a text input row.
	 *
	 * @param string                $name        Field name.
	 * @param string                $label       Visible label.
	 * @param string                $value       Current value.
	 * @param string                $description Optional description.
	 * @param array<string, string> $attrs       Extra input attributes.
	 */
	public function input_row(
		string $name,
		string $label,
		string $value,
		string $description = '',
		array $attrs = array()
	): string {
		return $this->field_row( 'text', $name, $label, $value, $description, $attrs );
	}

	/**
	 * Renders a number input row.
	 *
	 * @param string                $name        Field name.
	 * @param string                $label       Visible label.
	 * @param string                $value       Current value.
	 * @param string                $description Optional description.
	 * @param array<string, string> $attrs       Extra input attributes.
	 */
	public function number_row(
		string $name,
		string $label,
		string $value,
		string $description = '',
		array $attrs = array()
	): string {
		return $this->field_row( 'number', $name, $label, $value, $description, $attrs );
	}

	/**
	 * Renders a standardized status badge.
	 *
	 * @param string $label   Accessible label text.
	 * @param string $variant Badge variant.
	 */
	public function status_badge( string $label, string $variant = 'disabled' ): string {
		if ( ! in_array( $variant, self::BADGE_VARIANTS, true ) ) {
			$variant = 'disabled';
		}

		return sprintf(
			'<span class="mpcf-ui-status-badge mpcf-ui-status-badge--%1$s"><span class="mpcf-ui-status-badge__dot" aria-hidden="true"></span><span class="mpcf-ui-status-badge__label">%2$s</span></span>',
			esc_attr( $variant ),
			esc_html( $label )
		);
	}

	/**
	 * Renders a provider card.
	 *
	 * @param string $title         Provider title.
	 * @param string $description   Provider description.
	 * @param string $badge_label   Badge label.
	 * @param string $badge_variant Badge variant.
	 * @param string $action_html   Optional action link markup.
	 */
	public function provider_card(
		string $title,
		string $description,
		string $badge_label,
		string $badge_variant,
		string $action_html = ''
	): string {
		$action = '' !== $action_html
			? sprintf( '<div class="mpcf-ui-provider-card__actions">%s</div>', $action_html ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by caller.
			: '';

		return sprintf(
			'<article class="mpcf-ui-provider-card"><header class="mpcf-ui-provider-card__header"><h5 class="mpcf-ui-provider-card__title">%1$s</h5>%2$s</header><p class="mpcf-ui-provider-card__description">%3$s</p>%4$s</article>',
			esc_html( $title ),
			$this->status_badge( $badge_label, $badge_variant ),
			esc_html( $description ),
			$action
		);
	}

	/**
	 * Renders an information panel.
	 *
	 * @param string $title       Optional title.
	 * @param string $message     Message body (plain text).
	 * @param string $action_html Optional action markup.
	 */
	public function info_panel( string $title, string $message, string $action_html = '' ): string {
		return $this->panel( 'info', $title, $message, $action_html );
	}

	/**
	 * Renders a warning panel.
	 *
	 * @param string $title       Optional title.
	 * @param string $message     Message body (plain text).
	 * @param string $action_html Optional action markup.
	 */
	public function warning_panel( string $title, string $message, string $action_html = '' ): string {
		return $this->panel( 'warning', $title, $message, $action_html );
	}

	/**
	 * Renders a success panel.
	 *
	 * @param string $title       Optional title.
	 * @param string $message     Message body (plain text).
	 * @param string $action_html Optional action markup.
	 */
	public function success_panel( string $title, string $message, string $action_html = '' ): string {
		return $this->panel( 'success', $title, $message, $action_html );
	}

	/**
	 * Renders an empty state block.
	 *
	 * @param string $icon_class  Dashicon class.
	 * @param string $title       Title.
	 * @param string $message     Explanation.
	 * @param string $action_html Optional primary action markup.
	 */
	public function empty_state(
		string $icon_class,
		string $title,
		string $message,
		string $action_html = ''
	): string {
		$action = '' !== $action_html
			? sprintf( '<div class="mpcf-ui-empty-state__actions">%s</div>', $action_html ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by caller.
			: '';

		return sprintf(
			'<div class="mpcf-ui-empty-state"><span class="mpcf-ui-empty-state__icon dashicons %1$s" aria-hidden="true"></span><h4 class="mpcf-ui-empty-state__title">%2$s</h4><p class="mpcf-ui-empty-state__message">%3$s</p>%4$s</div>',
			esc_attr( $icon_class ),
			esc_html( $title ),
			esc_html( $message ),
			$action
		);
	}

	/**
	 * Renders a quick actions panel.
	 *
	 * @param string                          $title   Panel title.
	 * @param array<int,array<string,string>> $actions Action definitions with label, url, description keys.
	 */
	public function quick_actions_panel( string $title, array $actions ): string {
		$items = '';

		foreach ( $actions as $action ) {
			$label = $action['label'] ?? '';
			$url   = $action['url'] ?? '';
			$desc  = $action['description'] ?? '';

			if ( '' === $label || '' === $url ) {
				continue;
			}

			$desc_html = '' !== $desc
				? sprintf( '<span class="mpcf-ui-quick-action__description">%s</span>', esc_html( $desc ) )
				: '';

			$items .= sprintf(
				'<a class="mpcf-ui-quick-action" href="%1$s"><span class="mpcf-ui-quick-action__label">%2$s</span>%3$s</a>',
				esc_url( $url ),
				esc_html( $label ),
				$desc_html
			);
		}

		if ( '' === $items ) {
			return '';
		}

		return sprintf(
			'<section class="mpcf-ui-quick-actions"><h4 class="mpcf-ui-quick-actions__title">%s</h4><div class="mpcf-ui-quick-actions__grid">%s</div></section>',
			esc_html( $title ),
			$items
		);
	}

	/**
	 * Renders the sticky save bar markup.
	 *
	 * Labels are hardcoded English rather than translation-function calls:
	 * this library ships no text domain of its own (§8.2 build-time
	 * vendoring — a consumer that wants localized labels overrides via the
	 * `mpds_ui_strings` filter-equivalent left to the consumer's own render
	 * pass, not baked in here).
	 *
	 * @param string $scope Optional data attribute scope value.
	 */
	public function sticky_save_bar( string $scope = 'default' ): string {
		return sprintf(
			'<div class="mpcf-ui-sticky-save submit" data-mpcf-sticky-save data-mpcf-sticky-scope="%1$s"><span class="mpcf-ui-sticky-save__status" data-mpcf-unsaved-indicator hidden>%2$s</span><button type="button" class="button button-link mpcf-ui-sticky-save__discard" data-mpcf-sticky-discard hidden>%3$s</button><button type="submit" name="save" value="%4$s" class="button button-primary mpcf-ui-sticky-save__save">%4$s</button><span class="mpcf-ui-sticky-save__saved" data-mpcf-sticky-saved hidden>%5$s</span></div>',
			esc_attr( $scope ),
			esc_html( 'Unsaved changes' ),
			esc_html( 'Discard' ),
			esc_attr( 'Save changes' ),
			esc_html( 'All changes saved' )
		);
	}

	/**
	 * Renders pill navigation.
	 *
	 * @param string                         $aria_label Accessible nav label.
	 * @param array<int,array<string,mixed>> $items      Navigation items.
	 */
	public function pill_navigation( string $aria_label, array $items ): string {
		$list = '';

		foreach ( $items as $item ) {
			$url    = (string) ( $item['url'] ?? '' );
			$label  = (string) ( $item['label'] ?? '' );
			$icon   = (string) ( $item['icon'] ?? '' );
			$active = ! empty( $item['active'] );

			if ( '' === $url || '' === $label ) {
				continue;
			}

			$classes = array( 'mpcf-ui-pill-nav__item' );

			if ( $active ) {
				$classes[] = 'mpcf-ui-pill-nav__item--active';
			}

			$icon_html = '' !== $icon
				? sprintf( '<span class="mpcf-ui-pill-nav__icon dashicons %s" aria-hidden="true"></span>', esc_attr( $icon ) )
				: '';

			$list .= sprintf(
				'<li class="%1$s"><a class="mpcf-ui-pill-nav__link" href="%2$s"%3$s>%4$s<span class="mpcf-ui-pill-nav__label">%5$s</span></a></li>',
				esc_attr( implode( ' ', $classes ) ),
				esc_url( $url ),
				$active ? ' aria-current="page"' : '',
				$icon_html,
				esc_html( $label )
			);
		}

		return sprintf(
			'<nav class="mpcf-ui-pill-nav" aria-label="%1$s"><ul class="mpcf-ui-pill-nav__list">%2$s</ul></nav>',
			esc_attr( $aria_label ),
			$list
		);
	}

	/**
	 * Renders a loading skeleton placeholder.
	 *
	 * @param int $lines Number of skeleton lines.
	 */
	public function loading_skeleton( int $lines = 3 ): string {
		$lines = max( 1, min( 6, $lines ) );
		$rows  = str_repeat( '<span class="mpcf-ui-skeleton__line"></span>', $lines );

		return sprintf(
			'<div class="mpcf-ui-skeleton" aria-hidden="true" data-mpcf-loading-skeleton>%s</div>',
			$rows
		);
	}

	/**
	 * Opens a generic field group wrapper.
	 *
	 * @param string $extra_class Optional extra classes.
	 */
	public function field_group_open( string $extra_class = '' ): string {
		$classes = trim( 'mpcf-ui-field-group ' . $extra_class );

		return sprintf( '<div class="%s">', esc_attr( $classes ) );
	}

	/**
	 * Closes a field group wrapper.
	 */
	public function field_group_close(): string {
		return '</div>';
	}

	/**
	 * Builds attribute HTML from an associative array.
	 *
	 * @param array<string, string|null> $attrs Attributes.
	 */
	private function attr_html( array $attrs ): string {
		$html = '';

		foreach ( $attrs as $key => $attr_value ) {
			if ( null === $attr_value || '' === $attr_value ) {
				continue;
			}

			$html .= sprintf( ' %s="%s"', esc_attr( (string) $key ), esc_attr( (string) $attr_value ) );
		}

		return $html;
	}

	/**
	 * Renders a generic text/number field row.
	 *
	 * @param string                $type        Input type.
	 * @param string                $name        Field name.
	 * @param string                $label       Visible label.
	 * @param string                $value       Current value.
	 * @param string                $description Optional description.
	 * @param array<string, string> $attrs       Extra attributes.
	 */
	private function field_row(
		string $type,
		string $name,
		string $label,
		string $value,
		string $description = '',
		array $attrs = array()
	): string {
		$id        = $attrs['id'] ?? sanitize_key( str_replace( array( '[', ']' ), array( '-', '' ), $name ) );
		$attr_html = $this->attr_html( array_diff_key( $attrs, array( 'id' => true ) ) );

		$description_html = '' !== $description
			? sprintf( '<span class="mpcf-ui-field-row__description">%s</span>', esc_html( $description ) )
			: '';

		return sprintf(
			'<div class="mpcf-ui-field-row mpcf-ui-field-row--%1$s"><label class="mpcf-ui-field-row__label" for="%2$s">%3$s</label>%4$s<div class="mpcf-ui-field-row__control"><input type="%1$s" name="%5$s" id="%2$s" value="%6$s"%7$s /></div></div>',
			esc_attr( $type ),
			esc_attr( (string) $id ),
			esc_html( $label ),
			$description_html,
			esc_attr( $name ),
			esc_attr( $value ),
			$attr_html
		);
	}

	/**
	 * Renders a typed panel block.
	 *
	 * @param string $type        Panel type.
	 * @param string $title       Optional title.
	 * @param string $message     Message body.
	 * @param string $action_html Optional action markup.
	 */
	private function panel( string $type, string $title, string $message, string $action_html ): string {
		$title_html = '' !== $title
			? sprintf( '<h5 class="mpcf-ui-panel__title">%s</h5>', esc_html( $title ) )
			: '';

		$action = '' !== $action_html
			? sprintf( '<div class="mpcf-ui-panel__actions">%s</div>', $action_html ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by caller.
			: '';

		return sprintf(
			'<aside class="mpcf-ui-panel mpcf-ui-panel--%1$s" role="note">%2$s<p class="mpcf-ui-panel__message">%3$s</p>%4$s</aside>',
			esc_attr( $type ),
			$title_html,
			esc_html( $message ),
			$action
		);
	}
}
