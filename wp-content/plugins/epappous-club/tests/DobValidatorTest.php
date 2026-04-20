<?php

use PHPUnit\Framework\TestCase;

/**
 * @covers EPC_DOB_Validator
 */
class DobValidatorTest extends TestCase {

    public function test_valid_ymd(): void {
        $this->assertTrue( EPC_DOB_Validator::is_valid_ymd( '2000-06-15' ) );
        $this->assertFalse( EPC_DOB_Validator::is_valid_ymd( '2000-02-30' ) );
        $this->assertFalse( EPC_DOB_Validator::is_valid_ymd( 'not-a-date' ) );
    }

    public function test_validate_club_dob_empty_not_required(): void {
        $r = EPC_DOB_Validator::validate_club_dob( '', [ 'required' => false, 'min_age' => 0 ] );
        $this->assertTrue( $r === true );
    }

    public function test_validate_club_dob_empty_required(): void {
        $r = EPC_DOB_Validator::validate_club_dob( '', [ 'required' => true, 'min_age' => 0 ] );
        $this->assertInstanceOf( \WP_Error::class, $r );
        $this->assertSame( 'epc_dob_required', $r->get_error_code() );
    }

    public function test_validate_min_age(): void {
        $past = gmdate( 'Y-m-d', strtotime( '-30 years' ) );
        $r    = EPC_DOB_Validator::validate_club_dob( $past, [ 'required' => true, 'min_age' => 18 ] );
        $this->assertTrue( $r === true );
    }
}
