<?php
/**
 * Personal-data exporter and eraser for collaboration requests.
 *
 * Requests hold contact details of people who may never have an account, so
 * the lookup is by email address, matching how WordPress privacy requests
 * identify a person.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Requests;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RequestPrivacy {
	/** @var RequestRepository */
	private $repository;

	public function __construct( RequestRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * @param mixed $exporters Registered exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_exporter( $exporters ): array {
		$exporters                        = is_array( $exporters ) ? $exporters : array();
		$exporters['music-wave-requests'] = array(
			'exporter_friendly_name' => __( 'درخواست‌های همکاری MusicWave', 'music-wave-core' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * @param mixed $erasers Registered erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_eraser( $erasers ): array {
		$erasers                        = is_array( $erasers ) ? $erasers : array();
		$erasers['music-wave-requests'] = array(
			'eraser_friendly_name' => __( 'درخواست‌های همکاری MusicWave', 'music-wave-core' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function export( string $email, int $page = 1 ): array {
		unset( $page );
		$items  = array();
		$labels = array(
			'name'     => __( 'نام', 'music-wave-core' ),
			'email'    => __( 'ایمیل', 'music-wave-core' ),
			'phone'    => __( 'تلفن', 'music-wave-core' ),
			'subject'  => __( 'عنوان', 'music-wave-core' ),
			'message'  => __( 'توضیحات', 'music-wave-core' ),
			'type'     => __( 'نوع درخواست', 'music-wave-core' ),
			'role'     => __( 'نقش', 'music-wave-core' ),
			'budget'   => __( 'بودجه', 'music-wave-core' ),
			'deadline' => __( 'زمان مورد نظر', 'music-wave-core' ),
		);
		foreach ( $this->repository->find_by_email( $email ) as $request ) {
			$data = array();
			foreach ( $labels as $key => $label ) {
				if ( isset( $request[ $key ] ) && '' !== (string) $request[ $key ] ) {
					$data[] = array(
						'name'  => $label,
						'value' => (string) $request[ $key ],
					);
				}
			}
			if ( array() !== $request['links'] ) {
				$data[] = array(
					'name'  => __( 'پیوندها', 'music-wave-core' ),
					'value' => implode( ', ', $request['links'] ),
				);
			}
			$items[] = array(
				'group_id'    => 'music-wave-requests',
				'group_label' => __( 'درخواست‌های همکاری', 'music-wave-core' ),
				'item_id'     => 'music-wave-request-' . (int) $request['id'],
				'data'        => $data,
			);
		}

		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function erase( string $email, int $page = 1 ): array {
		unset( $page );
		$removed = 0;
		foreach ( $this->repository->find_by_email( $email ) as $request ) {
			if ( $this->repository->delete( (int) $request['id'] ) ) {
				++$removed;
			}
		}

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
