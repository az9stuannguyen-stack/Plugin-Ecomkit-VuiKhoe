<?php
defined( 'ABSPATH' ) || exit;
/** Only filters verified in the official Get Order tutorial are enabled. */
final class Ecomkit_Vuikhoe_Lazada_Order_Query {
	public const MAX_LIMIT = 100;
	public const MAX_OFFSET = 5000;
	public function __construct( public readonly DateTimeImmutable $created_after, public readonly ?string $status = null, public readonly int $limit = 100, public readonly int $offset = 0 ) {
		if ( $limit < 1 || $limit > self::MAX_LIMIT || $offset < 0 || $offset > self::MAX_OFFSET || ( null !== $status && 1 !== preg_match( '/^[a-z_]{1,40}$/D', $status ) ) ) { throw new InvalidArgumentException( 'LAZADA_ORDER_QUERY_INVALID' ); }
	}
	public static function date( DateTimeInterface $date ): string { return $date->format( 'Y-m-d\TH:i:sP' ); }
	public function parameters(): array {
		$params = array( 'created_after' => self::date( $this->created_after ), 'limit' => $this->limit, 'offset' => $this->offset );
		if ( null !== $this->status ) { $params['status'] = $this->status; }
		return $params;
	}
	public function at_offset( int $offset ): self { return new self( $this->created_after, $this->status, $this->limit, $offset ); }
}
