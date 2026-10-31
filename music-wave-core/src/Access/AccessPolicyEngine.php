<?php
/**
 * Deny-by-default access decision service.
 *
 * Evaluates all canonical access modes consistently:
 * - `public`                 : accessible to everyone
 * - `logged_in`              : accessible to any authenticated WordPress user (`user_id > 0`)
 * - `purchase`               : requires ownership of at least one mapped WooCommerce product
 * - `membership`             : requires active matching VIP/membership level (or `membership_absent_behavior` when no provider)
 * - `purchase_or_membership` : true logical OR — unlocked by either WooCommerce ownership OR active matching membership
 * - `restricted`             : administratively locked (admins & explicit manual grants still pass)
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Access;

use InvalidArgumentException;
use ManaCore\MusicWave\Core\Commerce\PurchaseChecker;
use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AccessPolicyEngine {
	/** @var ReleaseRepository */
	private $repository;

	/** @var PurchaseChecker */
	private $purchase_checker;

	/** @var MembershipProvider */
	private $membership_provider;

	/** @var ManualAccessProvider */
	private $manual_provider;

	public function __construct(
		ReleaseRepository $repository,
		PurchaseChecker $purchase_checker,
		?MembershipProvider $membership_provider = null,
		?ManualAccessProvider $manual_provider = null
	) {
		$this->repository          = $repository;
		$this->purchase_checker    = $purchase_checker;
		$this->membership_provider = null !== $membership_provider ? $membership_provider : new NullMembershipProvider();
		$this->manual_provider     = null !== $manual_provider ? $manual_provider : new NullManualAccessProvider();
	}

	public function decide( int $release_id, AccessSubject $subject ): AccessDecision {
		$decision = $this->evaluate( $release_id, $subject );
		$filtered = apply_filters( 'music_wave_access_decision', $decision, $release_id, $subject );

		return $filtered instanceof AccessDecision ? $filtered : $decision;
	}

	private function evaluate( int $release_id, AccessSubject $subject ): AccessDecision {
		try {
			$mode = (string) $this->repository->get( $release_id, 'mw_access_mode' );
		} catch ( InvalidArgumentException $exception ) {
			return AccessDecision::deny( 'invalid_release' );
		}

		$mode = sanitize_key( $mode );
		if ( $subject->can( 'manage_options' ) ) {
			return AccessDecision::allow( 'administrator_override', $mode );
		}

		if ( 'public' === $mode ) {
			return AccessDecision::allow( 'public', $mode );
		}

		if ( $subject->user_id() > 0 && $this->manual_provider->has_access( $subject->user_id(), $release_id ) ) {
			return AccessDecision::allow( 'manual_grant', $mode );
		}

		switch ( $mode ) {
			case 'logged_in':
				return $subject->user_id() > 0
					? AccessDecision::allow( 'logged_in', $mode )
					: AccessDecision::deny( 'login_required', $mode );
			case 'purchase':
				return $this->purchase_checker->user_owns_release( $subject->user_id(), $release_id )
					? AccessDecision::allow( 'purchase', $mode )
					: AccessDecision::deny( 'purchase_required', $mode );
			case 'membership':
				return $this->membership_access( $release_id, $subject, $mode );
			case 'purchase_or_membership':
				return $this->hybrid_access( $release_id, $subject, $mode );
			case 'restricted':
				return AccessDecision::deny( 'restricted', $mode );
			default:
				return AccessDecision::deny( 'unknown_mode', '' !== $mode ? $mode : 'restricted' );
		}
	}

	private function hybrid_access( int $release_id, AccessSubject $subject, string $mode ): AccessDecision {
		if ( $this->purchase_checker->user_owns_release( $subject->user_id(), $release_id ) ) {
			return AccessDecision::allow( 'purchase', $mode );
		}

		$has_products = ! empty( $this->repository->product_ids( $release_id ) );
		$behavior     = MembershipAbsencePolicy::mode();

		// When no membership module is active at all: the absent-behavior decides.
		// `open_download` opens hybrid releases on the membership side even when a
		// WooCommerce product is mapped; `allow` keeps paid products purchasable.
		if ( $this->membership_provider instanceof NullMembershipProvider ) {
			if ( MembershipAbsencePolicy::opens_hybrid( $behavior ) ) {
				return AccessDecision::allow( MembershipAbsencePolicy::REASON_OPEN, $mode );
			}
			if ( $has_products ) {
				return AccessDecision::deny( 'purchase_required', $mode );
			}

			return $this->absent_provider_decision( $mode );
		}

		$levels = $this->effective_levels( $release_id );
		if ( $subject->user_id() > 0 && ! empty( $levels ) && $this->membership_provider->has_access( $subject->user_id(), $levels ) ) {
			return AccessDecision::allow( 'membership', $mode );
		}

		return $has_products
			? AccessDecision::deny( 'purchase_or_membership_required', $mode )
			: AccessDecision::deny( 'membership_required', $mode );
	}

	private function membership_access( int $release_id, AccessSubject $subject, string $mode ): AccessDecision {
		if ( $this->membership_provider instanceof NullMembershipProvider ) {
			return $this->absent_provider_decision( $mode );
		}

		$levels = $this->effective_levels( $release_id );
		if ( $subject->user_id() > 0 && ! empty( $levels ) && $this->membership_provider->has_access( $subject->user_id(), $levels ) ) {
			return AccessDecision::allow( 'membership', $mode );
		}

		return AccessDecision::deny( 'membership_required', $mode );
	}

	private function absent_provider_decision( string $mode ): AccessDecision {
		$behavior = MembershipAbsencePolicy::mode();

		if ( MembershipAbsencePolicy::MODE_DENY === $behavior ) {
			return AccessDecision::deny( 'membership_provider_absent', $mode );
		}

		if ( MembershipAbsencePolicy::MODE_OPEN_DOWNLOAD === $behavior ) {
			return AccessDecision::allow( MembershipAbsencePolicy::REASON_OPEN, $mode );
		}

		return AccessDecision::allow( 'membership_provider_absent', $mode );
	}

	/**
	 * Effective level slugs for membership-gated releases (falls back cleanly to default VIP level if unset).
	 *
	 * @return array<int, string>
	 */
	private function effective_levels( int $release_id ): array {
		$levels = AccessModePolicy::sanitize_levels( $this->repository->get( $release_id, 'mw_membership_levels' ) );
		if ( ! empty( $levels ) ) {
			return $levels;
		}

		return AccessModePolicy::default_membership_levels();
	}

	/**
	 * Determine whether a user holds any active membership across the registered provider.
	 */
	public function has_active_membership( int $user_id ): bool {
		if ( $user_id < 1 || $this->membership_provider instanceof NullMembershipProvider ) {
			return false;
		}
		if ( method_exists( $this->membership_provider, 'has_any_active' ) ) {
			return (bool) $this->membership_provider->has_any_active( $user_id );
		}

		return $this->membership_provider->has_access( $user_id, array( 'mw_vip', 'vip' ) );
	}
}
