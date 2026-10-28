<?php
/**
 * WooCommerce purchase ownership adapter.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Commerce;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PurchaseChecker {
	/** @var ReleaseRepository */
	private $repository;

	public function __construct( ReleaseRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Determine whether a user owns any mapped product.
	 *
	 * Explicit lifecycle policy (ADR 0004, PROJECT_PLAN.md Stage 3):
	 * ownership follows WooCommerce paid order state — `processing` and
	 * `completed` orders grant access; `refunded`, `cancelled`, and `failed`
	 * orders do not. Every delivery decision re-evaluates this live, so a
	 * refund revokes access immediately without any cached entitlement.
	 */
	public function user_owns_release( int $user_id, int $release_id ): bool {
		if ( $user_id <= 0 || ReleasePostType::KEY !== get_post_type( $release_id ) || ! function_exists( 'wc_customer_bought_product' ) ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( false === $user ) {
			return false;
		}

		$owns = false;
		foreach ( $this->repository->product_ids( $release_id ) as $product_id ) {
			if ( wc_customer_bought_product( $user->user_email, $user_id, $product_id ) ) {
				$owns = true;
				break;
			}
		}

		/**
		 * Filter the purchase-ownership decision for one release.
		 *
		 * Integrations with custom order lifecycles (deposits, invoicing,
		 * gifting) can adjust the decision; the result stays subject to the
		 * deny-by-default access policy engine.
		 *
		 * @param bool $owns       Ownership per the documented Woo lifecycle policy.
		 * @param int  $user_id    Acting user.
		 * @param int  $release_id Release being checked.
		 */
		return (bool) apply_filters( 'music_wave_purchase_owns_release', $owns, $user_id, $release_id );
	}
}
