<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * Raised for any Etsy API / sync failure. The message is always safe to show to the shop owner.
 */
class Api_Error extends \Exception {
	public function status() {
		return (int) $this->getCode();
	}
}
