<?php
namespace EventFlow;

defined( 'ABSPATH' ) || exit;

final class Event_Date {
	public static function parse( string $value ): ?\DateTimeImmutable {
		if ( '' === $value ) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d\\TH:i', $value, wp_timezone() );
		$errors = \DateTimeImmutable::getLastErrors();
		if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) || $date->format( 'Y-m-d\\TH:i' ) !== $value ) {
			return null;
		}
		return $date;
	}

	public static function display( string $value, string $format, string $fallback = '' ): string {
		$date = self::parse( $value );
		return $date ? wp_date( $format, $date->getTimestamp(), wp_timezone() ) : $fallback;
	}
}
