<?php
/** Excel Batch to Shopee exact order_sn reconciliation. */

defined( 'ABSPATH' ) || exit;

use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

final class Ecomkit_Vuikhoe_Shopee_Reconciliation_Service {
	private const ERROR_SOURCE = 'SHOPEE_RECON';
	private const MAX_WINDOW_SECONDS = 1296000;
	private const DETAIL_FIELDS = array( 'buyer_username', 'recipient_address', 'item_list', 'estimated_shipping_fee', 'actual_shipping_fee' );
	public array $last_diagnostic = array();

	public function __construct( private ?Ecomkit_Vuikhoe_Shopee_Order_Service $orders = null ) {
		$this->orders ??= new Ecomkit_Vuikhoe_Shopee_Order_Service();
	}

	/** @return array<string,mixed> */
	public function reconcile_batch( int $batch_id, ?int $requested_connection_id = null, ?array $auto_limits = null ): array {
		global $wpdb;

		$tables = Ecomkit_Vuikhoe_DB::table_names();
		$batch = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['batches']} WHERE id = %d", $batch_id ), ARRAY_A );
		if ( ! is_array( $batch ) || 'EXCEL' !== ( $batch['source_type'] ?? '' ) || ! in_array( (string) ( $batch['status'] ?? '' ), array( 'SUCCESS', 'WARNING' ), true ) ) {
			$this->fail( 'SHOPEE_RECON_BATCH_NOT_ELIGIBLE', 'BATCH_ELIGIBILITY' );
		}
		$excel_orders = $wpdb->get_results( $wpdb->prepare( "SELECT id, marketplace_order_id, platform, order_date, matching_status, connection_id, raw_source_metadata, source_refs, provider_raw_data, provider_normalized_data, provider_updated_at FROM {$tables['orders']} WHERE batch_id = %d AND platform = %s ORDER BY id ASC", $batch_id, 'SHOPEE' ), ARRAY_A );
		$excel_orders = is_array( $excel_orders ) ? $excel_orders : array();
		if ( ! $excel_orders ) {
			$this->fail( 'SHOPEE_RECON_NO_SHOPEE_ORDERS', 'BATCH_ELIGIBILITY' );
		}

		$connection = $this->select_connection( $requested_connection_id );
		$connection_id = (int) $connection['id'];
		$timezone = wp_timezone();
		$batch_metadata = json_decode( (string) ( $batch['source_metadata'] ?? '' ), true );
		$batch_metadata = is_array( $batch_metadata ) ? $batch_metadata : array();
		$date_column = isset( $batch_metadata['date_column'] ) && (int) $batch_metadata['date_column'] > 0 ? (int) $batch_metadata['date_column'] : ( (string) ( $batch_metadata['parser_version'] ?? '' ) === 'wp2b-v1' ? 3 : 0 );

		$by_sn = array(); $by_id = array();
		$dates_by_id = array();
		$planning_errors = array();
		foreach ( $excel_orders as $order ) {
			$sn = (string) ( $order['marketplace_order_id'] ?? '' );
			if ( '' === $sn || isset( $by_sn[ $sn ] ) ) {
				$planning_errors[] = $this->error_record( $batch, $order, 'SHOPEE_RECON_PROVIDER_IDENTITY_MISMATCH', 'RECON_PLAN', 'Mã đơn Shopee trong Batch không duy nhất hoặc không hợp lệ.', 'Kiểm tra dữ liệu import của Batch trước khi đối chiếu.' );
				continue;
			}
			$by_sn[ $sn ] = $order;
			$by_id[ (int) $order['id'] ] = $order;
			$raw = json_decode( (string) ( $order['raw_source_metadata'] ?? '' ), true );
			$storage = is_array( $raw ) ? (string) ( $raw['order_date_storage'] ?? 'LOCAL' ) : 'LOCAL';
			$date = self::stored_order_local_date( (string) ( $order['order_date'] ?? '' ), $timezone, $storage );
			if ( null === $date && $date_column > 0 ) {
				$date = self::parse_excel_local_date( is_array( $raw ) ? ( $raw['cells'][ (string) $date_column ] ?? null ) : null, $timezone );
			}
			if ( null === $date ) {
				$planning_errors[] = $this->error_record( $batch, $order, 'SHOPEE_RECON_ORDER_DATE_MISSING', 'RECON_PLAN', 'Không đọc được Ngày đặt của đơn Shopee.', 'Kiểm tra cột Ngày đặt trong file Excel; đơn này chưa được kết luận NOT FOUND.' );
				continue;
			}
			$dates_by_id[ (int) $order['id'] ] = $date;
		}
		$identity_errors = array_filter( $planning_errors, static fn( array $error ): bool => 'SHOPEE_RECON_PROVIDER_IDENTITY_MISMATCH' === $error['error_code'] );
		if ( $identity_errors ) {
			$summary = array( 'connection_id' => $connection_id, 'shop_id' => (string) $connection['external_shop_id'], 'started_at' => current_time( 'mysql', true ), 'completed_at' => current_time( 'mysql', true ), 'windows' => array(), 'provider_windows_executed' => 0, 'shopee_api_calls' => 0, 'excel_shopee_count' => count( $excel_orders ), 'provider_count' => 0, 'matched_count' => 0, 'missing_count' => 0, 'extra_count' => 0, 'detail_count' => 0, 'detail_missing_count' => 0, 'missing_date_count' => 0, 'extra_order_sns' => array(), 'status' => 'ERROR' );
			$this->persist( $batch, $batch_metadata, $connection_id, array(), array(), array(), array(), array(), false, $planning_errors, $summary );
			return $summary;
		}

		$windows = self::derive_windows( array_values( $dates_by_id ), $timezone );
		$all_windows_count = count( $windows );
		$window_offset = is_array( $auto_limits ) ? max( 0, (int) ( $auto_limits['window_offset'] ?? 0 ) ) : 0;
		if ( $window_offset > 0 ) { $planning_errors = array(); } // Already persisted on the first bounded window.
		if ( is_array( $auto_limits ) ) { $windows = array_slice( $windows, $window_offset, max( 1, min( 2, (int) ( $auto_limits['max_windows'] ?? 1 ) ) ) ); }
		$started_at = current_time( 'mysql', true );
		$provider_by_sn = array();
		$complete_order_ids = array();
		$provider_errors = array();
		$window_results = array();
		$api_calls = 0;
		if ( is_array( $auto_limits ) && $window_offset >= $all_windows_count && $all_windows_count > 0 ) { $this->fail( 'SHOPEE_RECON_WINDOW_OFFSET_INVALID', 'RECON_PLAN' ); }
		foreach ( $windows as $window ) {
			$window_ids = array_keys( array_filter( $dates_by_id, static fn( string $date ): bool => $date >= $window['start_date'] && $date <= $window['end_date'] ) );
			$window_excel_sns = array_values( array_map( static fn( int $id ): string => (string) $by_id[ $id ]['marketplace_order_id'], $window_ids ) );
			$result = null;
			try {
				if ( is_array( $auto_limits ) ) {
					$continuation = (array) ( $auto_limits['continuation'] ?? array() );
					$cursor = $continuation['cursor'] ?? null;
					if ( null !== $cursor && ( ! is_string( $cursor ) || '' === $cursor ) ) { throw new RuntimeException( 'SHOPEE_RECON_CURSOR_INVALID' ); }
					$provider_ids = (array) ( $continuation['provider_ids'] ?? array() );
					$seen = (array) ( $continuation['seen_cursors'] ?? array() );
					$request_ids = (array) ( $continuation['request_ids'] ?? array() );
					$page_count = (int) ( $continuation['page_count'] ?? 0 );
					if ( $page_count < 0 || $page_count >= 100 || count( $provider_ids ) > 10000 ) { throw new RuntimeException( 'SHOPEE_RECON_PAGE_LIMIT_EXCEEDED' ); }
					$limit = max( 1, min( 3, (int) ( $auto_limits['max_pages'] ?? 2 ) ) );
					for ( $page = 0; $page < $limit; $page++ ) {
						$list = $this->orders->get_order_list_page( $connection_id, 'create_time', $window['time_from'], $window['time_to'], 100, $cursor );
						$page_count++; $request_ids[] = $list['request_id'];
						foreach ( $list['orders'] as $provider_order ) { $provider_ids[ (string) $provider_order['order_sn'] ] = true; }
						if ( ! $list['more'] ) { $result = array( 'orders' => array_map( static fn( mixed $sn ): array => array( 'order_sn' => (string) $sn ), array_keys( $provider_ids ) ), 'page_count' => $page_count, 'request_ids' => $request_ids ); break; }
						$next = (string) $list['next_cursor'];
						if ( '' === $next || $next === $cursor || isset( $seen[ $next ] ) || $page_count >= 100 || count( $provider_ids ) > 10000 ) { throw new RuntimeException( 'SHOPEE_RECON_PAGINATION_INCOMPLETE' ); }
						$seen[ $next ] = true; $cursor = $next;
					}
					if ( ! isset( $result ) ) {
						return array( 'status' => 'PROCESSING', 'matched_count' => (int) ( $batch_metadata['shopee_reconciliation']['matched_count'] ?? 0 ), 'missing_count' => (int) ( $batch_metadata['shopee_reconciliation']['missing_count'] ?? 0 ), 'detail_count' => (int) ( $batch_metadata['shopee_reconciliation']['detail_count'] ?? 0 ), 'total_windows' => $all_windows_count, 'next_window_offset' => $window_offset, 'continuation' => array( 'cursor' => $cursor, 'provider_ids' => $provider_ids, 'seen_cursors' => $seen, 'request_ids' => $request_ids, 'page_count' => $page_count ) );
					}
				} else { $result = $this->orders->get_all_orders( $connection_id, 'create_time', $window['time_from'], $window['time_to'], 100 ); }
				$api_calls += (int) $result['page_count'];
				foreach ( $result['orders'] as $provider_order ) {
					$provider_by_sn[ $provider_order['order_sn'] ] = $provider_order;
				}
				foreach ( $window_ids as $order_id ) {
					$complete_order_ids[ (int) $order_id ] = true;
				}
				$provider_sns = array_values( array_map( static fn( array $row ): string => (string) $row['order_sn'], $result['orders'] ) );
				$sets = self::compare_exact_sets( $window_excel_sns, $provider_sns );
				$window_results[] = array( 'connection_id' => $connection_id, 'shop_reference' => (string) $connection['external_shop_id'], 'time_range_field' => 'create_time', 'start_date' => $window['start_date'], 'end_date' => $window['end_date'], 'time_from' => $window['time_from'], 'time_to' => $window['time_to'], 'status' => 'SUCCESS', 'list_request_success' => true, 'pagination_complete' => true, 'request_ids' => array_values( array_filter( (array) ( $result['request_ids'] ?? array() ), static fn( mixed $id ): bool => is_string( $id ) && 1 === preg_match( '/\A[A-Za-z0-9_-]{1,128}\z/D', $id ) ) ), 'page_count' => (int) $result['page_count'], 'excel_count' => count( $window_excel_sns ), 'provider_count' => count( $provider_sns ), 'intersection_count' => count( $sets['matched'] ), 'excel_order_ids' => $window_excel_sns, 'provider_order_ids' => $provider_sns, 'intersection' => $sets['matched'], 'missing_in_provider' => $sets['missing'], 'extra_in_provider' => $sets['extra'] );
			} catch ( Throwable $exception ) {
				$api_calls += max( 1, (int) ( $this->orders->last_diagnostic['pagination_page'] ?? 1 ) );
				$is_partial = (int) ( $page_count ?? 0 ) > 0 || (int) ( $this->orders->last_diagnostic['pagination_page'] ?? 1 ) > 1 || in_array( $exception->getMessage(), array( 'SHOPEE_ORDER_PAGINATION_STALLED', 'SHOPEE_ORDER_PAGE_LIMIT_EXCEEDED', 'SHOPEE_RECON_PAGINATION_INCOMPLETE', 'SHOPEE_RECON_PAGE_LIMIT_EXCEEDED' ), true );
				$error_code = $is_partial ? 'SHOPEE_RECON_PAGINATION_INCOMPLETE' : 'SHOPEE_RECON_LIST_FAILED';
				$provider_errors[] = $this->error_record( $batch, null, $error_code, 'RECON_LIST', 'Không thể đọc đầy đủ tất cả trang đơn Shopee trong một khoảng ngày.', 'Thử đối chiếu lại; các đơn trong khoảng lỗi chưa được kết luận NOT FOUND.' );
				$window_results[] = array( 'connection_id' => $connection_id, 'shop_reference' => (string) $connection['external_shop_id'], 'time_range_field' => 'create_time', 'start_date' => $window['start_date'], 'end_date' => $window['end_date'], 'time_from' => $window['time_from'], 'time_to' => $window['time_to'], 'status' => 'INCOMPLETE', 'list_request_success' => false, 'pagination_complete' => false, 'excel_count' => count( $window_excel_sns ), 'excel_order_ids' => $window_excel_sns, 'failure_code' => $error_code, 'classification' => sanitize_key( $exception->getMessage() ) );
			}
		}

		$matched = array();
		$missing = array();
		foreach ( $by_sn as $sn => $order ) {
			$id = (int) $order['id'];
			if ( ! isset( $complete_order_ids[ $id ] ) ) {
				continue;
			}
			if ( array_key_exists( $sn, $provider_by_sn ) ) {
				$matched[ $sn ] = $order;
			} else {
				$missing[ $sn ] = $order;
			}
		}
		$extra = array_values( array_diff( array_keys( $provider_by_sn ), array_keys( $by_sn ) ) );

		$details_by_sn = array();
		$detail_missing = array();
		$detail_failed = false;
		if ( $matched ) {
			try {
				$detail = $this->orders->get_order_details_batched( $connection_id, array_keys( $matched ), self::DETAIL_FIELDS, is_array( $auto_limits ) ? max( 1, min( 2, (int) ( $auto_limits['max_detail_batches'] ?? 1 ) ) ) : PHP_INT_MAX );
				$api_calls += count( $detail['request_ids'] );
				$details_by_sn = $detail['orders_by_sn'];
				$detail_missing = $detail['missing_order_sn'];
				if ( $detail['extra_order_sn'] || (int) $detail['duplicate_count'] > 0 ) {
					$provider_errors[] = $this->error_record( $batch, null, 'SHOPEE_RECON_PROVIDER_IDENTITY_MISMATCH', 'RECON_DETAIL', 'Shopee trả về danh tính đơn không khớp yêu cầu chi tiết.', 'Chạy lại đối chiếu và liên hệ hỗ trợ nếu lỗi lặp lại.' );
					$detail_failed = true;
					$details_by_sn = array();
				}
			} catch ( Throwable $exception ) {
				$api_calls++;
				$provider_errors[] = $this->error_record( $batch, null, 'SHOPEE_RECON_DETAIL_FAILED', 'RECON_DETAIL', 'Không thể tải đầy đủ chi tiết các đơn Shopee đã khớp.', 'Thử đối chiếu lại; dữ liệu chi tiết cũ được giữ nguyên.' );
				$detail_failed = true;
			}
		}
		foreach ( $detail_missing as $sn ) {
			$provider_errors[] = $this->error_record( $batch, $matched[ $sn ] ?? null, 'SHOPEE_RECON_DETAIL_MISSING', 'RECON_DETAIL', 'Shopee không trả về chi tiết cho đơn đã xuất hiện trong danh sách.', 'Thử đối chiếu lại hoặc kiểm tra quyền API của shop.' );
		}

		$all_errors = array_merge( $planning_errors, $provider_errors );
		$status = $provider_errors ? 'INCOMPLETE' : ( $planning_errors || $detail_missing ? 'WARNING' : 'SUCCESS' );
		$summary = array(
			'connection_id'       => $connection_id,
			'shop_id'             => (string) $connection['external_shop_id'],
			'started_at'          => $started_at,
			'completed_at'        => current_time( 'mysql', true ),
			'windows'             => $window_results,
			'provider_windows_executed' => count( $window_results ),
			'shopee_api_calls'     => $api_calls,
			'excel_shopee_count'  => count( $excel_orders ),
			'provider_count'      => count( $provider_by_sn ),
			'matched_count'       => count( $matched ),
			'missing_count'       => count( $missing ),
			'extra_count'         => count( $extra ),
			'detail_count'        => count( $details_by_sn ),
			'detail_missing_count'=> count( $detail_missing ),
			'missing_date_count'  => count( array_filter( $planning_errors, static fn( array $error ): bool => 'SHOPEE_RECON_ORDER_DATE_MISSING' === $error['error_code'] ) ),
			'extra_order_sns'     => array_slice( $extra, 0, 100 ),
			'status'              => $status,
			'total_windows'       => $all_windows_count,
			'next_window_offset'  => $window_offset + count( $windows ),
		);
		if ( is_array( $auto_limits ) && $window_offset > 0 && is_array( $batch_metadata['shopee_reconciliation'] ?? null ) ) {
			$previous = $batch_metadata['shopee_reconciliation'];
			foreach ( array( 'provider_windows_executed', 'shopee_api_calls', 'provider_count', 'matched_count', 'missing_count', 'extra_count', 'detail_count', 'detail_missing_count', 'missing_date_count' ) as $count_key ) { $summary[ $count_key ] += (int) ( $previous[ $count_key ] ?? 0 ); }
			$summary['windows'] = array_merge( (array) ( $previous['windows'] ?? array() ), $summary['windows'] );
			$summary['extra_order_sns'] = array_slice( array_unique( array_merge( (array) ( $previous['extra_order_sns'] ?? array() ), $summary['extra_order_sns'] ) ), 0, 100 );
			if ( 'SUCCESS' !== (string) ( $previous['status'] ?? 'SUCCESS' ) && 'SUCCESS' === $summary['status'] ) { $summary['status'] = (string) $previous['status']; }
		}
		$summary['classification'] = self::classify_summary( $summary );
		if ( in_array( $summary['classification'], array( 'SHOPEE_RECON_PROVIDER_WINDOW_EMPTY', 'SHOPEE_RECON_ZERO_INTERSECTION', 'SHOPEE_RECON_PARTIAL_MATCH' ), true ) && 'SUCCESS' === $summary['status'] ) { $summary['status'] = 'WARNING'; }

		$this->persist( $batch, $batch_metadata, $connection_id, $complete_order_ids, $matched, $missing, $details_by_sn, $detail_missing, $detail_failed, $all_errors, $summary );
		return $summary;
	}

	/** @return array<int,array{start_date:string,end_date:string,time_from:int,time_to:int}> */
	public static function derive_windows( array $dates, DateTimeZone $timezone ): array {
		$dates = array_values( array_unique( array_filter( $dates, static fn( mixed $date ): bool => is_string( $date ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) ) );
		sort( $dates, SORT_STRING );
		$windows = array();
		$start = null;
		$previous = null;
		foreach ( $dates as $date ) {
			$current = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $timezone );
			if ( false === $current || $current->format( 'Y-m-d' ) !== $date ) {
				continue;
			}
			$candidate_seconds = null !== $start ? $current->setTime( 23, 59, 59 )->getTimestamp() - $start->setTime( 0, 0, 0 )->getTimestamp() : 0;
			$must_split = null !== $previous && ( $previous->modify( '+1 day' )->format( 'Y-m-d' ) !== $date || $candidate_seconds > self::MAX_WINDOW_SECONDS );
			if ( $must_split ) {
				$windows[] = self::window( $start, $previous );
				$start = $current;
			}
			$start ??= $current;
			$previous = $current;
		}
		if ( null !== $start && null !== $previous ) {
			$windows[] = self::window( $start, $previous );
		}
		return $windows;
	}

	/** @return array{matched:string[],missing:string[],extra:string[]} */
	public static function compare_exact_sets( array $excel, array $provider ): array {
		$excel = array_values( array_unique( array_filter( $excel, 'is_string' ) ) );
		$provider = array_values( array_unique( array_filter( $provider, 'is_string' ) ) );
		return array(
			'matched' => array_values( array_intersect( $excel, $provider ) ),
			'missing' => array_values( array_diff( $excel, $provider ) ),
			'extra'   => array_values( array_diff( $provider, $excel ) ),
		);
	}

	/** Classify only after every planned provider window finished; absence is never inferred from an incomplete page. */
	public static function classify_summary( array $summary ): ?string {
		$windows = (array) ( $summary['windows'] ?? array() );
		if ( (int) ( $summary['excel_shopee_count'] ?? 0 ) < 1 || ! $windows || count( $windows ) !== (int) ( $summary['total_windows'] ?? count( $windows ) ) || (int) ( $summary['next_window_offset'] ?? count( $windows ) ) < (int) ( $summary['total_windows'] ?? count( $windows ) ) ) { return null; }
		foreach ( $windows as $window ) { if ( 'SUCCESS' !== ( $window['status'] ?? '' ) || false === ( $window['pagination_complete'] ?? true ) ) { return in_array( (string) ( $window['failure_code'] ?? '' ), array( 'SHOPEE_RECON_PAGINATION_INCOMPLETE', 'SHOPEE_RECON_LIST_FAILED' ), true ) ? $window['failure_code'] : 'SHOPEE_RECON_PAGINATION_INCOMPLETE'; } }
		if ( (int) ( $summary['missing_date_count'] ?? 0 ) > 0 ) { return null; }
		$matched = (int) ( $summary['matched_count'] ?? 0 ); $excel = (int) $summary['excel_shopee_count'];
		if ( 0 === $matched ) { return 0 === (int) ( $summary['provider_count'] ?? 0 ) ? 'SHOPEE_RECON_PROVIDER_WINDOW_EMPTY' : 'SHOPEE_RECON_ZERO_INTERSECTION'; }
		return $matched < $excel ? 'SHOPEE_RECON_PARTIAL_MATCH' : null;
	}

	public static function parse_excel_local_date( mixed $value, DateTimeZone $timezone ): ?string {
		if ( null === $value || '' === trim( (string) $value ) ) {
			return null;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			try {
				return ExcelDate::excelToDateTimeObject( (float) $value, $timezone )->format( 'Y-m-d' );
			} catch ( Throwable $exception ) {
				return null;
			}
		}
		$text = trim( (string) preg_replace( '/\s+/u', ' ', trim( (string) $value ) ) );
		foreach ( array( 'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d' ) as $format ) {
			$date = DateTimeImmutable::createFromFormat( '!' . $format, $text, $timezone );
			$errors = DateTimeImmutable::getLastErrors();
			if ( false !== $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $date->format( $format ) === $text ) {
				return $date->format( 'Y-m-d' );
			}
		}
		return null;
	}

	/** @return array<string,mixed>|null */
	public function get_batch_result( int $batch_id ): ?array {
		global $wpdb;
		$tables = Ecomkit_Vuikhoe_DB::table_names();
		$batch = $wpdb->get_row( $wpdb->prepare( "SELECT source_metadata FROM {$tables['batches']} WHERE id = %d AND source_type = %s", $batch_id, 'EXCEL' ), ARRAY_A );
		if ( ! is_array( $batch ) ) {
			return null;
		}
		$metadata = json_decode( (string) $batch['source_metadata'], true );
		$summary = is_array( $metadata ) && is_array( $metadata['shopee_reconciliation'] ?? null ) ? $metadata['shopee_reconciliation'] : null;
		if ( ! is_array( $summary ) ) {
			return null;
		}
		$date_column = isset( $metadata['date_column'] ) && (int) $metadata['date_column'] > 0 ? (int) $metadata['date_column'] : ( (string) ( $metadata['parser_version'] ?? '' ) === 'wp2b-v1' ? 3 : 0 );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT marketplace_order_id, matching_status, order_date, raw_source_metadata, source_refs, provider_normalized_data FROM {$tables['orders']} WHERE batch_id = %d AND platform = %s ORDER BY id ASC", $batch_id, 'SHOPEE' ), ARRAY_A );
		$safe_rows = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$normalized = json_decode( (string) ( $row['provider_normalized_data'] ?? '' ), true );
			$raw = json_decode( (string) ( $row['raw_source_metadata'] ?? '' ), true );
			$precision = is_array( $raw ) ? (string) ( $raw['order_date_precision'] ?? 'DATETIME' ) : 'DATETIME';
			$storage = is_array( $raw ) ? (string) ( $raw['order_date_storage'] ?? 'LOCAL' ) : 'LOCAL';
			$display_date = self::format_stored_order_date( (string) ( $row['order_date'] ?? '' ), $precision, wp_timezone(), $storage );
			if ( '' === $display_date && $date_column > 0 ) {
				$display_date = (string) ( self::parse_excel_local_date( is_array( $raw ) ? ( $raw['cells'][ (string) $date_column ] ?? null ) : null, wp_timezone() ) ?? '' );
			}
			$safe_rows[] = array(
				'order_sn'           => (string) $row['marketplace_order_id'],
				'matching_status'     => (string) ( $row['matching_status'] ?? '' ),
				'order_date'          => $display_date,
				'source_refs'         => json_decode( (string) ( $row['source_refs'] ?? '' ), true ) ?: array(),
				'provider_status'     => is_array( $normalized ) ? (string) ( $normalized['providerStatus'] ?? '' ) : '',
				'provider_created_at' => is_array( $normalized ) ? (string) ( $normalized['providerCreatedAt'] ?? '' ) : '',
				'provider_updated_at' => is_array( $normalized ) ? (string) ( $normalized['providerUpdatedAt'] ?? '' ) : '',
			);
		}
		return array( 'summary' => $summary, 'orders' => $safe_rows );
	}

	/** @return array<int,array<string,mixed>> */
	public function ready_connections(): array {
		$config = ( new Ecomkit_Vuikhoe_Shopee_Config() )->get();
		return array_values( array_filter( ( new Ecomkit_Vuikhoe_Marketplace_Connection_Service() )->list_shopee( (string) ( $config['fingerprint'] ?? '' ) ), static fn( array $row ): bool => ! empty( $row['credential_ready'] ) ) );
	}

	/** @return array<string,mixed> */
	private function select_connection( ?int $requested_id ): array {
		$ready = $this->ready_connections();
		if ( ! $ready ) {
			$this->fail( 'SHOPEE_RECON_CONNECTION_NOT_READY', 'CONNECTION_SELECT' );
		}
		if ( null !== $requested_id && $requested_id > 0 ) {
			foreach ( $ready as $connection ) {
				if ( (int) $connection['id'] === $requested_id ) {
					return $connection;
				}
			}
			$this->fail( 'SHOPEE_RECON_CONNECTION_NOT_READY', 'CONNECTION_SELECT' );
		}
		if ( 1 !== count( $ready ) ) {
			$this->fail( 'SHOPEE_RECON_CONNECTION_AMBIGUOUS', 'CONNECTION_SELECT' );
		}
		return $ready[0];
	}

	private static function stored_order_local_date( string $value, DateTimeZone $timezone, string $storage = 'UTC' ): ?string {
		if ( '' === $value ) {
			return null;
		}
		$source_timezone = 'UTC' === $storage ? new DateTimeZone( 'UTC' ) : $timezone;
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, $source_timezone );
		$errors = DateTimeImmutable::getLastErrors();
		return false !== $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $date->format( 'Y-m-d H:i:s' ) === $value ? $date->setTimezone( $timezone )->format( 'Y-m-d' ) : null;
	}

	public static function format_stored_order_date( string $value, string $precision, DateTimeZone $timezone, string $storage = 'UTC' ): string {
		if ( '' === $value ) {
			return '';
		}
		$source_timezone = 'UTC' === $storage ? new DateTimeZone( 'UTC' ) : $timezone;
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, $source_timezone );
		$errors = DateTimeImmutable::getLastErrors();
		if ( false === $date || ( false !== $errors && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) || $date->format( 'Y-m-d H:i:s' ) !== $value ) {
			return '';
		}
		$format = 'DATE' === $precision ? get_option( 'date_format' ) : get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		return wp_date( $format, $date->getTimestamp(), $timezone );
	}

	/** @return array{start_date:string,end_date:string,time_from:int,time_to:int} */
	private static function window( DateTimeImmutable $start, DateTimeImmutable $end ): array {
		return array( 'start_date' => $start->format( 'Y-m-d' ), 'end_date' => $end->format( 'Y-m-d' ), 'time_from' => $start->setTime( 0, 0, 0 )->getTimestamp(), 'time_to' => $end->setTime( 23, 59, 59 )->getTimestamp() );
	}

	/** @param array<string,mixed> $batch @param array<string,mixed>|null $order @return array<string,mixed> */
	private function error_record( array $batch, ?array $order, string $code, string $stage, string $message, string $suggestion ): array {
		$source = is_array( $order ) ? ( json_decode( (string) ( $order['source_refs'] ?? '' ), true ) ?: array() ) : array();
		return array( 'batch_id' => (int) $batch['id'], 'order_id' => is_array( $order ) ? (int) $order['id'] : null, 'source' => self::ERROR_SOURCE, 'stage' => $stage, 'marketplace' => 'SHOPEE', 'filename' => $batch['source_filename'] ?: null, 'sheet_name' => $source['sheet'] ?? null, 'row_number' => $source['row'] ?? null, 'order_code' => is_array( $order ) ? (string) ( $order['marketplace_order_id'] ?? '' ) : null, 'error_code' => $code, 'severity' => 'ERROR', 'friendly_message' => $message, 'suggestion' => $suggestion, 'created_at' => current_time( 'mysql', true ) );
	}

	/** @param array<string,mixed> $batch @param array<string,mixed> $metadata */
	private function persist( array $batch, array $metadata, int $connection_id, array $complete_ids, array $matched, array $missing, array $details, array $detail_missing, bool $detail_failed, array $errors, array $summary ): void {
		global $wpdb;
		$tables = Ecomkit_Vuikhoe_DB::table_names();
		$now = current_time( 'mysql', true );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			$this->fail( 'SHOPEE_RECON_PERSIST_FAILED', 'RECON_PERSIST' );
		}
		try {
			foreach ( $complete_ids as $order_id => $_ ) {
				if ( false === $wpdb->update( $tables['orders'], array( 'connection_id' => $connection_id, 'matched_at' => $now, 'updated_at' => $now ), array( 'id' => (int) $order_id ) ) ) {
					throw new RuntimeException( 'SHOPEE_RECON_PERSIST_FAILED' );
				}
			}
			foreach ( $missing as $order ) {
				if ( false === $wpdb->update( $tables['orders'], array( 'matching_status' => 'NOT_FOUND_IN_SHOPEE', 'updated_at' => $now ), array( 'id' => (int) $order['id'] ) ) ) {
					throw new RuntimeException( 'SHOPEE_RECON_PERSIST_FAILED' );
				}
			}
			if ( ! $detail_failed ) {
				foreach ( $detail_missing as $sn ) {
					if ( isset( $matched[ $sn ] ) && false === $wpdb->update( $tables['orders'], array( 'matching_status' => 'DETAIL_MISSING', 'updated_at' => $now ), array( 'id' => (int) $matched[ $sn ]['id'] ) ) ) {
						throw new RuntimeException( 'SHOPEE_RECON_PERSIST_FAILED' );
					}
				}
				foreach ( $details as $sn => $detail ) {
					if ( ! isset( $matched[ $sn ] ) || $sn !== (string) ( $detail['order_sn'] ?? '' ) ) {
						throw new RuntimeException( 'SHOPEE_RECON_PROVIDER_IDENTITY_MISMATCH' );
					}
					$order = $matched[ $sn ];
					$provider_epoch = self::provider_epoch( $detail['update_time'] ?? null );
					$stored_epoch = ! empty( $order['provider_updated_at'] ) ? strtotime( (string) $order['provider_updated_at'] . ' UTC' ) : false;
					$data = array( 'matching_status' => 'MATCHED', 'updated_at' => $now );
					$may_replace = empty( $order['provider_raw_data'] ) || ( null !== $provider_epoch && ( false === $stored_epoch || $provider_epoch >= $stored_epoch ) );
					if ( $may_replace ) {
						$raw_json = wp_json_encode( $detail, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
						$normalized_json = wp_json_encode( Ecomkit_Vuikhoe_Shopee_Order_Normalizer::normalize( $detail ), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
						if ( ! is_string( $raw_json ) || ! is_string( $normalized_json ) ) {
							throw new RuntimeException( 'SHOPEE_RECON_PERSIST_FAILED' );
						}
						$data['provider_raw_data'] = $raw_json;
						$data['provider_normalized_data'] = $normalized_json;
						$data['provider_updated_at'] = null !== $provider_epoch ? gmdate( 'Y-m-d H:i:s', $provider_epoch ) : null;
					}
					if ( false === $wpdb->update( $tables['orders'], $data, array( 'id' => (int) $order['id'] ) ) ) {
						throw new RuntimeException( 'SHOPEE_RECON_PERSIST_FAILED' );
					}
				}
			}
			foreach ( $errors as $error ) {
				if ( false === $wpdb->insert( $tables['errors'], $error ) ) {
					throw new RuntimeException( 'SHOPEE_RECON_PERSIST_FAILED' );
				}
			}
			$metadata['shopee_reconciliation'] = $summary;
			if ( false === $wpdb->update( $tables['batches'], array( 'source_metadata' => wp_json_encode( $metadata, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ), 'updated_at' => $now ), array( 'id' => (int) $batch['id'] ) ) ) {
				throw new RuntimeException( 'SHOPEE_RECON_PERSIST_FAILED' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'SHOPEE_RECON_PERSIST_FAILED' );
			}
		} catch ( Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' );
			$wpdb->insert( $tables['errors'], $this->error_record( $batch, null, 'SHOPEE_RECON_PERSIST_FAILED', 'RECON_PERSIST', 'Không thể lưu kết quả đối chiếu Shopee an toàn.', 'Kiểm tra Database Runtime Diagnostics rồi thử lại; dữ liệu Excel và snapshot cũ được giữ nguyên.' ) );
			$this->fail( 'SHOPEE_RECON_PERSIST_FAILED', 'RECON_PERSIST' );
		}
	}

	private static function provider_epoch( mixed $value ): ?int {
		if ( is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^[0-9]+$/', $value ) ) ) {
			$value = (int) $value;
			return $value > 0 ? $value : null;
		}
		return null;
	}

	private function fail( string $classification, string $stage ): never {
		$this->last_diagnostic = array( 'stage' => $stage, 'classification' => $classification );
		throw new Ecomkit_Vuikhoe_Shopee_Reconciliation_Exception( $classification, $this->last_diagnostic );
	}
}

final class Ecomkit_Vuikhoe_Shopee_Reconciliation_Exception extends RuntimeException {
	public function __construct( string $code, public readonly array $diagnostic = array() ) {
		parent::__construct( $code );
	}
}
